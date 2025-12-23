<?php
declare(strict_types=1);

namespace System\Controllers;

use Exception;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use System\Http\Request;
use System\Http\Response;
use System\Http\Json;
use System\Http\ApiResponse;
use System\Security\Csrf;
use System\Security\Crypto;
use System\Helpers\UrlHelper;
use System\Helpers\OriginHelper;
use System\Helpers\DateHelper;
use System\Helpers\UploadHelper;
use System\Helpers\PathHelper;

/**
 * Base Controller (Yantra optimized)
 *
 * Key principles:
 *  - No session_start() here (bootstrap should init SessionStore)
 *  - Avoid mixed response pipelines; provide safe helpers
 *  - Same-origin + CSRF for browser "unsafe" methods (optional)
 */
abstract class Controller
{
    protected Request $request;
    protected Response $response;

    public function __construct(Request $request, Response $response)
    {
        $this->request  = $request;
        $this->response = $response;

        $this->init();
    }

    /**
     * Override in derived controllers if needed.
     */
    protected function init(): void
    {
        // no-op
    }

    /* ---------------------------------------------------------------------
     * CSRF (framework primitive)
     * --------------------------------------------------------------------- */

    /**
     * Ensure CSRF token exists (returns token).
     * Useful for web pages that render forms.
     */
    protected function ensureCsrfToken(string $key = 'default'): string
    {
        return Csrf::token($key);
    }

    protected function csrfToken(string $key = 'default'): string
    {
        return Csrf::token($key);
    }

    protected function regenerateCsrf(string $key = 'default'): string
    {
        // Simple: clear and re-issue
        Csrf::clear($key);
        return Csrf::token($key);
    }

    protected function validateCsrf(?string $token, string $key = 'default', bool $rotateOnSuccess = true): bool
    {
        return Csrf::validate((string)($token ?? ''), $key, $rotateOnSuccess);
    }

    /**
     * Enforce CSRF for unsafe methods (POST/PUT/PATCH/DELETE).
     * If invalid -> deny 403.
     */
    protected function verifyCsrfTokenOrDeny(string $key = 'default'): void
    {
        $method = strtoupper($this->request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $token =
            $_POST['_csrf_token'] ??
            ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null) ??
            ($this->getHeader('X-CSRF-Token') ?? null);

        if (!$this->validateCsrf(is_string($token) ? $token : null, $key, true)) {
            $this->deny('CSRF token invalid.', 403);
        }
    }

    /* ---------------------------------------------------------------------
     * Origin / Access checks (web default)
     * --------------------------------------------------------------------- */

    /**
     * Handle OPTIONS preflight cheaply (web-friendly default).
     * For API-grade CORS use System\Security\Cors via middleware.
     */
    protected function handlePreflight(): void
    {
        $method = strtoupper($this->request->getMethod());
        if ($method !== 'OPTIONS') {
            return;
        }

        // Minimal: allow browser preflight to continue if same-origin
        $this->verifySameOrigin();

        $this->status(204);
        $this->header('Content-Length', '0');
        $this->terminate();
    }

    /**
     * Basic request verification:
     * - Allow CLI
     * - If X-Internal-Call header exists, it must be "1" or "true"
     * - Otherwise enforce same-origin + CSRF for unsafe methods
     */
    protected function verifyAccess(): void
    {
        if (PHP_SAPI === 'cli' || defined('STDIN')) {
            return;
        }

        $internal = (string)($this->getHeader('X-Internal-Call') ?? '');
        if ($internal !== '') {
            if ($internal === '1' || strtolower($internal) === 'true') {
                return;
            }
            $this->deny('Invalid internal call header', 403);
        }

        // Browser requests: enforce same-origin + CSRF for unsafe methods
        $this->verifySameOrigin();
        $this->verifyCsrfTokenOrDeny();
    }

    protected function verifySameOrigin(): void
    {
        $origin     = $this->getHeader('Origin');
        $referer    = $this->getHeader('Referer');
        $siteOrigin = $this->getSiteOrigin();

        // If Origin present: enforce it strictly
        if (is_string($origin) && $origin !== '') {
            if (!OriginHelper::isSameOrigin($origin, $siteOrigin)) {
                $this->deny('Cross-origin requests are not allowed', 403);
            }

            // Best-effort: echo allowed origin for same-origin scenarios
            $this->header('Access-Control-Allow-Origin', $origin);
            $this->header('Access-Control-Allow-Credentials', 'true');
            $this->header('Vary', 'Origin');
            return;
        }

        // If no Origin, fall back to Referer for browser POSTs (best-effort)
        if (is_string($referer) && $referer !== '') {
            if (!OriginHelper::isSameOrigin($referer, $siteOrigin)) {
                $this->deny('Cross-origin requests are not allowed', 403);
            }
        }
    }

    protected function isSameOrigin(string $a, string $b): bool
    {
        return OriginHelper::isSameOrigin($a, $b);
    }

    /**
     * Derive site origin from server vars.
     */
    protected function getSiteOrigin(): string
    {
        $server = $_SERVER;

        $isHttps = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || (($server['SERVER_PORT'] ?? '') === '443');

        $scheme = $isHttps ? 'https' : 'http';

        $hostHeader = $server['HTTP_HOST'] ?? ($server['SERVER_NAME'] ?? 'localhost');
        $hostParts  = explode(':', (string)$hostHeader);
        $host       = $hostParts[0];
        $port       = $hostParts[1] ?? ($server['SERVER_PORT'] ?? null);

        $defaultPort = $isHttps ? '443' : '80';
        $portPart = ($port !== null && (string)$port !== '' && (string)$port !== $defaultPort)
            ? ':' . $port
            : '';

        return $scheme . '://' . $host . $portPart;
    }

    /* ---------------------------------------------------------------------
     * Header helpers
     * --------------------------------------------------------------------- */

    protected function getHeader(string $name): ?string
    {
        // Prefer request header access if available
        if (method_exists($this->request, 'getHeader')) {
            $v = $this->request->getHeader($name);
            if (is_string($v) && $v !== '') return $v;
        }

        // PSR request if present
        if (method_exists($this->request, 'getPsrRequest')) {
            $psr = $this->request->getPsrRequest();
            if ($psr && method_exists($psr, 'getHeaderLine')) {
                $line = trim((string)$psr->getHeaderLine($name));
                if ($line !== '') return $line;
            }
        }

        // Fallback to $_SERVER
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return trim((string)$_SERVER[$key]);
        }

        // Common alt var
        if (strcasecmp($name, 'Authorization') === 0 && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }

        return null;
    }

    /* ---------------------------------------------------------------------
     * Response helpers (consistent + safe)
     * --------------------------------------------------------------------- */

    protected function header(string $name, string $value): void
    {
        if (method_exists($this->response, 'withHeader')) {
            $this->response->withHeader($name, $value);
            return;
        }
        header($name . ': ' . $value, true);
    }

    protected function status(int $code): void
    {
        if (method_exists($this->response, 'withStatus')) {
            $this->response->withStatus($code);
            return;
        }
        http_response_code($code);
    }

    /**
     * Deny request with JSON by default (safe base behavior).
     */
    protected function deny(string $message, int $status = 403, array $meta = []): void
    {
        ApiResponse::error($this->response, 'forbidden', $message, $status, $meta);
        $this->terminate();
    }

    protected function success(mixed $data = null, int $status = 200, array $headers = []): void
    {
        $payload = [
            'ok'   => true,
            'data' => $data,
        ];
        ApiResponse::json($this->response, $payload, $status, $headers);
        $this->terminate();
    }

    protected function error(string $message, int $status = 400, string $code = 'error', array $meta = []): void
    {
        ApiResponse::error($this->response, $code, $message, $status, $meta);
        $this->terminate();
    }

    protected function jsonErrorResponse(string $message, int $status = 400, string $code = 'error', array $meta = []): void
    {
        $this->error($message, $status, $code, $meta);
    }

    /**
     * Parse JSON body into array. If invalid -> error(400).
     */
    protected function parseJsonBody(bool $allowEmpty = true): array
    {
        $raw = '';

        if (method_exists($this->request, 'getPsrRequest')) {
            $psr = $this->request->getPsrRequest();
            if ($psr) {
                $raw = (string)$psr->getBody();
            }
        }

        try {
            $decoded = Json::decodeBody($raw, true, $allowEmpty);
        } catch (\Throwable $e) {
            $this->error('Invalid JSON payload', 400, 'invalid_json');
        }

        if (!is_array($decoded)) {
            $this->error('JSON payload must be an object', 400, 'invalid_json');
        }

        return $decoded;
    }

    /* ---------------------------------------------------------------------
     * Date helpers (delegated)
     * --------------------------------------------------------------------- */

    protected function toMysqlDate(string $dateString): string
    {
        $dt = DateHelper::parse($dateString);
        return $dt->format('Y-m-d');
    }

    protected function toMysqlDateTime(string $dateString): string
    {
        $dt = DateHelper::parse($dateString);
        return $dt->format('Y-m-d H:i:s');
    }

    protected function toMysqlTime(string $dateString): string
    {
        $dt = DateHelper::parse($dateString);
        return $dt->format('H:i:s');
    }

    protected function fromMysqlDate(string $dateString, string $format = 'd M Y'): string
    {
        $dt = DateHelper::parse($dateString);
        return $dt->format($format);
    }

    /* ---------------------------------------------------------------------
     * Upload helpers (kept compatible with your helper approach)
     * --------------------------------------------------------------------- */

    protected function handleUploadChunk(array $params = []): void
    {
        // Expecting chunk upload fields: fileId, index, total, and $_FILES['chunk']
        $fileId = (string)($_POST['fileId'] ?? '');
        $index  = (int)($_POST['index'] ?? -1);
        $total  = (int)($_POST['total'] ?? 0);

        if ($fileId === '' || $index < 0 || $total <= 0 || empty($_FILES['chunk'])) {
            $this->error('Invalid upload chunk parameters', 400, 'upload_invalid');
        }

        try {
            $tmpDir = PathHelper::join(sys_get_temp_dir(), 'yantra_uploads', $fileId);
            $this->ensureDir($tmpDir);

            UploadHelper::saveChunk($tmpDir, $fileId, $index, $_FILES['chunk']);
            $this->success(['fileId' => $fileId, 'index' => $index], 200);
        } catch (Exception $e) {
            $this->error('Chunk upload failed', 500, 'upload_failed');
        }
    }

    protected function handleUploadComplete(array $params = []): void
    {
        $fileId   = (string)($_POST['fileId'] ?? '');
        $filename = (string)($_POST['filename'] ?? 'upload.bin');
        $total    = (int)($_POST['total'] ?? 0);

        if ($fileId === '' || $total <= 0) {
            $this->error('Invalid upload completion parameters', 400, 'upload_invalid');
        }

        try {
            $tmpDir = PathHelper::join(sys_get_temp_dir(), 'yantra_uploads', $fileId);
            $destDir = PathHelper::join(BASEPATH ?? '.', 'storage', 'uploads');
            $this->ensureDir($destDir);

            $destPath = PathHelper::join($destDir, $filename);

            UploadHelper::assembleChunks($tmpDir, $destPath,$fileId,$filename, $total);

            $this->success(['path' => $destPath], 200);
        } catch (Exception $e) {
            $this->error('Upload completion failed', 500, 'upload_failed');
        }
    }

    protected function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new InvalidArgumentException('Failed to create directory: ' . $dir);
            }
        }
    }

    /* ---------------------------------------------------------------------
     * PSR emission compatibility (kept from your old design)
     * --------------------------------------------------------------------- */

    protected function emitPsrResponse(ResponseInterface $psrResp, bool $exit = true): void
    {
        // Prefer Laminas SapiEmitter if available
        if (class_exists(\Laminas\HttpHandlerRunner\Emitter\SapiEmitter::class) && !headers_sent()) {
            try {
                $emitter = new \Laminas\HttpHandlerRunner\Emitter\SapiEmitter();
                $emitter->emit($psrResp);
                if ($exit) $this->terminate();
                return;
            } catch (\Throwable $e) {
                error_log('[emitPsrResponse] SapiEmitter failed: ' . $e->getMessage());
            }
        }

        // Fallback minimal emitter
        if (!headers_sent()) {
            http_response_code($psrResp->getStatusCode());
            foreach ($psrResp->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        } else {
            error_log('[emitPsrResponse] headers already sent; emitting body only');
        }

        $body = $psrResp->getBody();
        if ($body->isSeekable()) $body->rewind();
        while (!$body->eof()) {
            echo $body->read(8192);
            if (function_exists('flush')) flush();
        }

        if ($exit) $this->terminate();
    }

    /**
     * Centralized termination point.
     */
    protected function terminate(): void
    {
        // hard stop to prevent accidental fallthrough in legacy controllers
        exit;
    }
}
