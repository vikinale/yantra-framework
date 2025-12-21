<?php
declare(strict_types=1);

namespace System\Controllers;

use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use System\Request;
use System\Response;
use System\Helpers\FormHelper;
use System\Helpers\UrlHelper;
use System\Helpers\OriginHelper;
use System\Helpers\JsonHelper;
use System\Helpers\DateHelper;
use System\Helpers\UploadHelper;
use System\Helpers\PathHelper;
use System\Helpers\ArrayHelper;
use System\Helpers\SecurityHelper;

/**
 * Class Controller
 *
 * Refactored controller: delegates low-level work to helpers.
 * Keeps the same behavior as the original class you provided but
 * moves direct session/files/CSRF/date/upload logic into helpers.
 *
 * Subclasses that relied on protected utility methods (toMysqlDate, emitPsrResponse, etc.)
 * will continue to work.
 */
class Controller
{
    protected Request $request;
    protected Response $response;

    /** Prefer JSON when denying */
    protected bool $denyWithJson = true;

    /**
     * Constructor - preserves injection of Request + Response.
     *
     * @throws Exception
     */
    public function __construct(Request $request, Response $response)
    {
        $this->request  = $request;
        $this->response = $response;

        $this->init();
        $this->ensureSession();
        $this->ensureCsrfToken();
    }

    /**
     * Lightweight init hook (theme / env)
     */
    private function init(): void
{
    // Reserved for application-level initialization (e.g., DI container wiring, view/theme setup).
}

/* -----------------------
     * Session helpers
     * ---------------------- */

    /**
     * Ensure PHP session is started.
     */
    protected function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    /* -----------------------
     * CSRF helpers (delegated)
     * ---------------------- */

    /**
     * Ensure CSRF token exists (delegates to FormHelper).
     */
    protected function ensureCsrfToken(): void
    {
        // FormHelper::generateCsrfToken is idempotent in practice (ensures a token exists)
        FormHelper::generateCsrfToken();
    }

    /**
     * Return a CSRF token suitable for embedding.
     */
    public function csrfToken(): string
    {
        // generate or return current token (FormHelper returns token string)
        return FormHelper::generateCsrfToken();
    }

    /**
     * Regenerate CSRF token immediately.
     */
    public function regenerateCsrf(): void
    {
        FormHelper::generateCsrfToken(); // will create a fresh token
    }

    /**
     * Validate token (delegates to FormHelper).
     */
    public function validateCsrf(?string $token = null): bool
    {
        return FormHelper::validateCsrfToken($token);
    }

    /**
     * Verify CSRF for unsafe HTTP methods or deny.
     */
    protected function verifyCsrfTokenOrDeny(): void
    {
        $method = strtoupper($this->request->getMethod() ?? 'GET');

        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        if (!FormHelper::validateCsrfToken(null)) {
            $this->deny('Invalid or missing CSRF token', 403);
        }
    }

    /* -----------------------
     * CORS / Origin helpers (delegated)
     * ---------------------- */

    /**
     * Handle OPTIONS preflight: same-origin allowed, cross-origin denied.
     */
    protected function handlePreflight(): void
    {
        $method = strtoupper($this->request->getMethod() ?? 'GET');
        if ($method !== 'OPTIONS') {
            return;
        }

        $origin = $this->getHeader('Origin') ?? null;
        if ($origin === null) {
            // no origin -> non-browser invocation: emit 200
            $resp = $this->response->getCoreResponse()->withStatus(200);
            $this->emitPsrResponse($resp, true);
        }

        if (!OriginHelper::isSameOrigin($origin, $this->getSiteOrigin())) {
            $this->deny('CORS preflight denied: cross-origin not allowed', 403);
        }

        $resp = $this->response->getCoreResponse()
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-CSRF-Token, X-Internal-Call')
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withStatus(204);

        $this->emitPsrResponse($resp, true);
    }

    /**
     * Verify access controls:
     *  - allow CLI
     *  - allow internal gateway header
     *  - otherwise require same-origin and CSRF for unsafe methods
     */
    protected function verifyAccess(): void
    {
        if (php_sapi_name() === 'cli' || defined('STDIN')) {
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

    /**
     * Verify same-origin using Origin or Referer header (delegates origin comparison).
     */
    protected function verifySameOrigin(): void
    {
        $origin  = $this->getHeader('Origin') ?? null;
        $referer = $this->getHeader('Referer') ?? null;
        $siteOrigin = $this->getSiteOrigin();

        if ($origin) {
            if (!OriginHelper::isSameOrigin($origin, $siteOrigin)) {
                $this->deny('Cross-origin requests are not allowed', 403);
            }

            // add CORS header and continue
            $this->response->getCoreResponse()
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true');

            return;
        }

        if ($referer) {
            if (!OriginHelper::isSameOrigin($referer, $siteOrigin)) {
                $this->deny('Invalid referer', 403);
            }
            return;
        }

        $this->deny('Missing Origin/Referer header', 403);
    }

    /**
     * Determine whether a provided origin or URL matches site origin.
     */
    protected function isSameOrigin(string $originOrUrl): bool
    {
        return OriginHelper::isSameOrigin($originOrUrl, $this->getSiteOrigin());
    }

    /**
     * Build current site origin: scheme://host[:port]
     *
     * Note: this mirrors previous behavior but is isolated here for clarity.
     */
    protected function getSiteOrigin(): string
    {
        $server = $_SERVER;
        $isHttps = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') || ($server['SERVER_PORT'] ?? '') === '443';
        $scheme = $isHttps ? 'https' : 'http';

        $hostHeader = $server['HTTP_HOST'] ?? ($server['SERVER_NAME'] ?? 'localhost');
        $hostParts = explode(':', (string)$hostHeader);
        $host = $hostParts[0];
        $port = $hostParts[1] ?? ($server['SERVER_PORT'] ?? null);

        $origin = $scheme . '://' . $host;
        if ($port && !in_array((int)$port, [80, 443], true)) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    /* -----------------------
     * Header helpers
     * ---------------------- */

    /**
     * Get header value. Preference:
     *  - Request->getHeader() (PSR wrapper)
     *  - $_SERVER HTTP_...
     */
    protected function getHeader(string $name): ?string
    {
        if (isset($this->request) && method_exists($this->request, 'getHeader')) {
            $val = $this->request->getHeader($name);
            if ($val !== null && $val !== '') {
                return (string)$val;
            }
        }

        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string)$_SERVER[$key];
        }

        if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return (string)$_SERVER[$name];
        }

        return null;
    }

    /* -----------------------
     * Deny / success / error helpers (lean)
     * ---------------------- */

    /**
     * Deny the request with message. Prefer JSON when possible.
     */
    protected function deny(string $message = 'Forbidden', int $status = 403): void
    {
        $payload = ['status' => 'error', 'message' => $message];

        if ($this->denyWithJson && isset($this->response) && method_exists($this->response, 'sendJson')) {
            $this->response->sendJson($payload, $status);
            return;
        }

        $psr = $this->response->getCoreResponse()
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody((new Psr17Factory())->createStream((string)$message))
            ->withStatus($status);

        $this->emitPsrResponse($psr, true);
    }

    /**
     * Send success payload (delegates to Response when available).
     *
     * @param mixed $payload
     * @param string $message
     * @param int $status
     */
    protected function success($payload = [], string $message = 'OK', int $status = 200): void
    {
        $data = ['status' => 'success', 'message' => $message, 'data' => $payload];

        if (isset($this->response) && method_exists($this->response, 'sendJson')) {
            $this->response->sendJson($data, $status);
            return;
        }

        $psr = $this->response->getCoreResponse()->json($data, $status);
        $this->emitPsrResponse($psr, true);
    }

    /**
     * Send error payload.
     */
    protected function error(string $message = 'Error', int $status = 400, $payload = null): void
    {
        $respArr = ['status' => 'error', 'message' => $message];
        if ($payload !== null) $respArr['data'] = $payload;

        if (isset($this->response) && method_exists($this->response, 'sendJson')) {
            $this->response->sendJson($respArr, $status);
            return;
        }

        $psr = $this->response->getCoreResponse()->json($respArr, $status);
        $this->emitPsrResponse($psr, true);
    }

    protected function jsonErrorResponse(string $message, int $statusCode = 400, array $errors = []): void
    {
        $payload = [
            'status'  => 'error',
            'code'    => $statusCode,
            'message' => $message,
            'errors'  => $errors,
            'time'    => date('c'),
            'version' => $this->apiVersion ?? 'v1',
        ];

        if (method_exists($this->response, 'sendJson')) {
            $this->response->sendJson($payload, $statusCode);
            return;
        }

        $psr = $this->response->getCoreResponse()->json($payload, $statusCode);
        $this->emitPsrResponse($psr, true);
    }


    /* -----------------------
     * JSON body parsing
     * ---------------------- */

    /**
     * Safely parse JSON request body and return associative array.
     * Uses JsonHelper::decode which throws an Exception on error.
     */
    protected function parseJsonBody(): array
    {
        $raw = (string)$this->request->getPsrRequest()->getBody();
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = JsonHelper::decode($raw, true);
        } catch (Exception $e) {
            $this->error('Invalid JSON payload', 400);
            // error() will emit and exit; static analyzer fallback:
            exit;
        }

        if (!is_array($decoded)) {
            $this->error('Invalid JSON payload structure', 400);
            exit;
        }

        return $decoded;
    }

    /* -----------------------
     * Date helpers (delegated)
     * ---------------------- */

    protected function toMysqlDate(string $dateString): string
    {
        try {
            $dt = DateHelper::parse($dateString);
            return $dt->format('Y-m-d');
        } catch (Exception $e) {
            throw new InvalidArgumentException("Invalid date format: $dateString");
        }
    }

    protected function toMysqlDateTime(string $datetimeString): string
    {
        try {
            $dt = DateHelper::parse($datetimeString);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            throw new InvalidArgumentException("Invalid datetime format: $datetimeString");
        }
    }

    protected function toMysqlTime(string $timeString): string
    {
        try {
            $dt = DateHelper::parse($timeString);
            return $dt->format('H:i:s');
        } catch (Exception $e) {
            throw new InvalidArgumentException("Invalid time format: $timeString");
        }
    }

    protected function fromMysqlDate(string $mysqlDate, string $outputFormat = 'd M Y'): string
    {
        try {
            $dt = DateHelper::parse($mysqlDate);
            return $dt->format($outputFormat);
        } catch (Exception $e) {
            throw new InvalidArgumentException("Invalid date format: $mysqlDate");
        }
    }

    /* -----------------------
     * Chunked uploads (delegated to UploadHelper)
     * ---------------------- */

    /**
     * Handle a single chunk upload. Delegates to UploadHelper.
     */
    protected function handleUploadChunk(array $options = []): array
    {
        $tempBase = $options['tempBase'] ?? __DIR__ . '/../../storage/uploads/tmp_chunks';
        $fileId   = $options['fileId'] ?? null;
        $index    = isset($options['index']) ? intval($options['index']) : null;

        if (!$fileId || $index === null) {
            return ['status' => false, 'message' => 'Missing required fields'];
        }

        if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
            return ['status' => false, 'message' => 'Invalid chunk file'];
        }

        return UploadHelper::saveChunk($tempBase, $fileId, $index, $_FILES['chunk']);
    }

    /**
     * Assemble uploaded chunks into final file (delegates to UploadHelper).
     */
    protected function handleUploadComplete(array $options = []): array
    {
        $tempBase    = $options['tempBase'] ?? __DIR__ . '/../../storage/uploads/tmp_chunks';
        $uploadsBase = $options['uploadsBase'] ?? __DIR__ . '/../../storage/uploads/final';
        $fileId      = $options['fileId'] ?? null;
        $filename    = $options['filename'] ?? null;
        $maxSize     = $options['maxSize'] ?? (50 * 1024 * 1024);

        if (!$fileId) {
            return ['status' => false, 'message' => 'Missing fileId'];
        }

        return UploadHelper::assembleChunks($tempBase, $uploadsBase, $fileId, $filename ?? 'file', $maxSize);
    }

    /* -----------------------
     * Utility ensure directory (thin wrapper)
     * ---------------------- */

    protected function ensureDir(string $path): void
    {
        PathHelper::ensureDirectory($path);
    }

    /* -----------------------
     * Response emitter (kept but cleaned)
     * ---------------------- */

    /**
     * Emit a PSR-7 response. Prefers Core\Response wrapper if present.
     *
     * @param ResponseInterface $psrResp
     * @param bool $exit
     */
    protected function emitPsrResponse(ResponseInterface $psrResp, bool $exit = true): void
    {
        // Prefer Core\Response wrapper if it exists
        if (class_exists(\System\Response::class)) {
            $wrapper = new \System\Response($psrResp);
            if ($exit) {
                $wrapper->emitAndExit();
                return;
            }
            $wrapper->emit();
            return;
        }

        // Attempt Laminas SapiEmitter
        if (class_exists(\Laminas\HttpHandlerRunner\Emitter\SapiEmitter::class) && !headers_sent()) {
            try {
                $emitter = new \Laminas\HttpHandlerRunner\Emitter\SapiEmitter();
                $emitter->emit($psrResp);
                if ($exit) exit;
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

        if ($exit) exit;
    }
}
