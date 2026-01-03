<?php
declare(strict_types=1);

namespace System\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
use System\Utilities\RequestCache;
use System\Utilities\Validator;
use System\Utilities\Sanitizer;
use RuntimeException;
use System\Exceptions\ValidationException;

class Request
{
    protected ServerRequestInterface $psr;    // underlying PSR-7 request
    protected string $basePath;
    private array $attributes = [];
    private ?RequestCache $requestCache = null;
    private Psr17Factory $psrFactory;

    /** @var array<string,mixed>|null */
    private ?array $filesCache = null;
    /**
     * Construct from an existing PSR request or build one from globals if null.
     */
    public function __construct(?ServerRequestInterface $psr = null)
    {
        $this->psrFactory = new Psr17Factory();

        if ($psr !== null) {
            $this->psr = $psr;
        } else {
            // convenience: create from globals
            $creator = new ServerRequestCreator(
                $this->psrFactory,
                $this->psrFactory,
                $this->psrFactory,
                $this->psrFactory
            );
            $this->psr = $creator->fromGlobals();
        }

        $this->basePath = $this->getBasePath();
    }

    /**
     * Return a header value (first line) or default.
     *
     * - Reads from PSR request headers (preferred)
     * - Falls back to $_SERVER if needed
     */
    public function header(string $name, $default = null): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return $default;
        }

        // Preferred: PSR-7 header line
        try {
            $value = $this->psr->getHeaderLine($name);
            if ($value !== '') {
                return $value;
            }
        } catch (\Throwable $e) {
            // ignore and fallback
        }

        // Fallback: server params (some stacks may not populate PSR headers consistently)
        $server = $this->psr->getServerParams();
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (isset($server[$key]) && is_string($server[$key]) && $server[$key] !== '') {
            return $server[$key];
        }

        // Extra fallbacks for common headers mapped differently
        if (strcasecmp($name, 'Content-Type') === 0) {
            $ct = $server['CONTENT_TYPE'] ?? ($_SERVER['CONTENT_TYPE'] ?? null);
            if (is_string($ct) && $ct !== '') return $ct;
        }
        if (strcasecmp($name, 'Content-Length') === 0) {
            $cl = $server['CONTENT_LENGTH'] ?? ($_SERVER['CONTENT_LENGTH'] ?? null);
            if (is_string($cl) && $cl !== '') return $cl;
        }

        // Final fallback to $_SERVER (legacy environments)
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }

        return $default;
    }

    /**
     * Return all headers as an associative array of "Header-Name" => "value".
     * (Optional helper)
     */
    public function headers(): array
    {
        $out = [];

        // PSR headers (canonical)
        try {
            foreach ($this->psr->getHeaders() as $name => $values) {
                $out[$name] = implode(', ', (array)$values);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Fallback from server params if PSR headers empty
        if ($out === []) {
            $server = $this->psr->getServerParams();
            foreach ($server as $k => $v) {
                if (!is_string($v)) continue;

                if (str_starts_with($k, 'HTTP_')) {
                    $name = str_replace('_', '-', strtolower(substr($k, 5)));
                    $name = implode('-', array_map('ucfirst', explode('-', $name)));
                    $out[$name] = $v;
                } elseif ($k === 'CONTENT_TYPE') {
                    $out['Content-Type'] = $v;
                } elseif ($k === 'CONTENT_LENGTH') {
                    $out['Content-Length'] = $v;
                }
            }
        }

        return $out;
    }


    /**
     * Get Authorization Bearer token if present.
     *
     * Looks in:
     * - Authorization header (preferred)
     * - Server params fallback (HTTP_AUTHORIZATION / REDIRECT_HTTP_AUTHORIZATION)
     *
     * @return string|null
     */
    public function bearerToken(): ?string
    {
        // Prefer normalized header helper
        $auth = (string)$this->header('Authorization', '');

        // Fallbacks (some servers don’t pass Authorization into headers)
        if ($auth === '') {
            $server = $this->psr->getServerParams();
            $auth = (string)($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            if ($auth === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $auth = (string)$_SERVER['HTTP_AUTHORIZATION'];
            }
            if ($auth === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $auth = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            }
        }

        $auth = trim($auth);
        if ($auth === '') return null;

        // Accept: "Bearer <token>" (case-insensitive)
        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $auth, $m)) {
            $token = trim((string)$m[1]);

            // Strip surrounding quotes if any
            if ($token !== '' && ($token[0] === '"' || $token[0] === "'")) {
                $token = trim($token, "\"'");
            }

            return $token !== '' ? $token : null;
        }

        return null;
    }

    /**
     * Get CSRF token from request.
     *
     * Sources checked (in order):
     * - Body fields: _csrf, csrf, _token
     * - Query fields: _csrf, csrf, _token
     * - Headers: X-CSRF-Token, X-XSRF-TOKEN
     *
     * @param array<string> $keys Override accepted body/query keys if you want.
     * @return string|null
     */
    public function csrfToken(array $keys = ['_csrf', 'csrf', '_token']): ?string
    {
        // 1) Body / JSON / form (your all() already merges query + body + json)
        try {
            $all = $this->all();
            foreach ($keys as $k) {
                if (isset($all[$k])) {
                    $v = $all[$k];
                    if (is_string($v)) {
                        $v = trim($v);
                        if ($v !== '') return $v;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 2) Query params explicitly
        try {
            $q = $this->getQuery();
            if (is_array($q)) {
                foreach ($keys as $k) {
                    if (isset($q[$k]) && is_string($q[$k])) {
                        $v = trim($q[$k]);
                        if ($v !== '') return $v;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 3) Headers
        $h = trim((string)$this->header('X-CSRF-Token', ''));
        if ($h !== '') return $h;

        $h = trim((string)$this->header('X-XSRF-TOKEN', ''));
        if ($h !== '') return $h;

        return null;
    }

    public function csrfHeader(): ?string
    {
        $h = trim((string)$this->header('X-CSRF-Token', ''));
        if ($h !== '') return $h;

        $h = trim((string)$this->header('X-XSRF-TOKEN', ''));
        return $h !== '' ? $h : null;
    }

    /**
     * True if Accept header indicates JSON.
     */
    public function acceptsJson(): bool
    {
        $accept = (string)$this->header('Accept', '');
        $accept = strtolower($accept);
        return $accept !== '' && str_contains($accept, 'application/json');
    }

    /**
     * True if this request should be answered with JSON:
     * - XHR (XMLHttpRequest)
     * - OR Accept: application/json
     *
     * Useful for your SPA login flow.
     */
    public function wantsJson(): bool
    {
        return $this->isAjax() || $this->acceptsJson();
    }


    /**
     * factory helper
     */
    public static function fromGlobals(): self
    {
        return new self(null);
    }

    /**
     * Return the underlying PSR request.
     */
    public function getPsrRequest(): ServerRequestInterface
    {
        return $this->psr;
    }

    // --- Basic getters using PSR-7 where appropriate --- //

    public function getMethod(): string
    {
        return $this->psr->getMethod();
    }

    public function getPath(int $index = -1): ?string
    {
        $uri = $this->psr->getUri();
        $path = $uri->getPath() ?: '/';
        $path = $this->stripBasePath($path);
        if ($index >= 0) {
            $parts = explode('/', trim($path, '/'));
            // maintain same behavior as old code (reverse etc)
            $parts = array_reverse($parts);
            return $parts[$index] ?? null;
        }
        return $path;
    }

    protected function stripBasePath($path): string
    {
        if (str_starts_with($path, $this->basePath)) {
            return substr($path, strlen($this->basePath)) ?: '/';
        }
        return $path;
    }

    public function getBasePath(): string
    {
        // derive from SCRIPT_NAME as before (works in common setups)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '/');
        $basePath = str_replace('\\', '/', dirname($scriptName));
        if ($basePath === '/') $basePath = '';
        return $basePath;
    }

    public function getQuery(?string $key = null, $default = null)
    {
        $query = $this->psr->getQueryParams();
        if ($key === null) return $query;
        return $query[$key] ?? $default;
    }

    public function all(): array
    {
        $data = $this->psr->getQueryParams();
        if (!is_array($data)) {
            $data = [];
        }

        // Parsed body (form/multipart, etc.)
        $parsed = $this->psr->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            // body overrides query on key collision (typical expectation)
            $data = array_replace($data, $parsed);
        }

        // JSON body (only when Content-Type indicates JSON)
        $contentType = strtolower($this->psr->getHeaderLine('Content-Type'));
        if (str_contains($contentType, 'application/json')) {
            $raw = (string) $this->psr->getBody();
            if ($raw !== '') {
                try {
                    $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($json) && $json !== []) {
                        $data = array_replace($data, $json);
                    }
                } catch (\JsonException $e) {
                    // For all(): ignore invalid JSON and return what we have.
                    // (Validation should be strict; all() should be best-effort.)
                }
            }
        }

        return $data;
    }

    public function input(string $key, $default = null)
    {
        // support dot notation
        $data = $this->all();
        if ($key === '') return $data;
        $keys = explode('.', $key);
        return $this->getValueFromNestedArray($data, $keys, $default);
    }

    public function jsonInput(string $key, $default = null)
    {
        $contentType = $this->psr->getHeaderLine('Content-Type');
        $body = (string)$this->psr->getBody();
        if (stripos($contentType, 'application/json') === false) {
            // fallback: try decode anyway
            $decoded = @json_decode($body, true);
        } else {
            $decoded = @json_decode($body, true);
        }
        if (!is_array($decoded)) return $default;
        $keys = explode('.', $key);
        return $this->getValueFromNestedArray($decoded, $keys, $default);
    }

    // Files — mapping PSR UploadedFileInterface to legacy structure
    // public function allFiles(): array
    // {
    //     $out = [];
    //     foreach ($this->psr->getUploadedFiles() as $key => $uploaded) {
    //         $out[$key] = $this->convertUploadedFile($uploaded);
    //     }
    //     return $out;
    // }

    public function allFiles(): array
    {
        if ($this->filesCache !== null) {
            return $this->filesCache;
        }

        $psrFiles = $this->psr->getUploadedFiles(); // PSR-7 UploadedFileInterface tree
        $this->filesCache = $this->mapUploadedFiles($psrFiles);

        return $this->filesCache;
    }
    
    /**
     * @param array<string,mixed> $files
     * @return array<string,mixed>
     */
    private function mapUploadedFiles(array $files): array
    {
        $out = [];

        foreach ($files as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->mapUploadedFiles($value);
                continue;
            }

            if ($value instanceof \Psr\Http\Message\UploadedFileInterface) {
                // Wrap WITHOUT moving
                $out[$key] = new \System\Http\UploadedFile($value); // your wrapper
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    public function storeUploadedFile(
        \Psr\Http\Message\UploadedFileInterface $file,
        string $destinationDir,
        ?string $filename = null
    ): string {
        if (!is_dir($destinationDir)) {
            if (!mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
                throw new \RuntimeException("Cannot create directory: {$destinationDir}");
            }
        }

        $safeName = $filename ?: $this->safeUploadName($file->getClientFilename() ?: 'file');
        $target = rtrim($destinationDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;

        // If already moved, moveTo() will throw in most PSR implementations.
        // You can pre-check by ensuring target not exists and catching exceptions.
        $file->moveTo($target);

        return $target;
    }

    private function safeUploadName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^\w\-.]+/u', '-', $name) ?? 'file';
        $name = trim($name, '-');
        return $name !== '' ? $name : 'file';
    }

    private function convertUploadedFile($uploaded)
    {
        // $uploaded can be UploadedFileInterface or array for nested
        if (is_array($uploaded)) {
            $arr = [];
            foreach ($uploaded as $k => $u) $arr[$k] = $this->convertUploadedFile($u);
            return $arr;
        }
        /* @var Psr\Http\Message\UploadedFileInterface $uploaded */
        if ($uploaded === null) return null;
        $tmp = sys_get_temp_dir() . '/psrupload_' . uniqid();
        // moveTo may copy stream; if not moved yet we can get stream contents
        try {
            $uploaded->moveTo($tmp);
            $size = filesize($tmp);
            return [
                'name' => $uploaded->getClientFilename(),
                'type' => $uploaded->getClientMediaType(),
                'tmp_name' => $tmp,
                'error' => 0,
                'size' => $size,
            ];
        } catch (\Throwable $e) {
            // fallback: try reading stream to temp
            $stream = $uploaded->getStream();
            $contents = (string)$stream;
            file_put_contents($tmp, $contents);
            return [
                'name' => $uploaded->getClientFilename(),
                'type' => $uploaded->getClientMediaType(),
                'tmp_name' => $tmp,
                'error' => 0,
                'size' => strlen($contents),
            ];
        }
    }

    /**
     * Return single file (legacy signature)
     */
    private function file(string $key): ?array
    {
        $files = $this->allFiles();
        return $files[$key] ?? null;
    }

    public function inputFileBase64(string $name, $default = null)
    {
        $file = $this->file($name);
        if (is_null($file) || ($file['error'] ?? 1) !== 0) return $default;
        $fileContent = file_get_contents($file['tmp_name']);
        return base64_encode($fileContent);
    }

    public function inputFileBlob(string $name, $default = null)
    {
        $file = $this->file($name);
        if (is_null($file) || ($file['error'] ?? 1) !== 0) return $default;
        return file_get_contents($file['tmp_name']);
    }

    public function getHeader(string $name)
    {
        return $this->psr->getHeaderLine($name);
    }

    public function isAjax(): bool
    {
        return strcasecmp($this->getHeader('X-Requested-With'), 'XMLHttpRequest') === 0;
    }

    public function isPost(): bool { return strtoupper($this->getMethod()) === 'POST'; }
    public function isDelete(): bool { return strtoupper($this->getMethod()) === 'DELETE'; }
    public function isPut(): bool { return strtoupper($this->getMethod()) === 'PUT'; }
    public function isGet(): bool { return strtoupper($this->getMethod()) === 'GET'; }

    public function has(string $key): bool
    {
        $all = $this->all();
        return isset($all[$key]);
    }

    private function getValueFromNestedArray(array $data, array $keys, $default)
    {
        if (empty($keys)) return $default;
        $key = array_shift($keys);
        if (isset($data[$key]) && is_array($data[$key])) {
            return empty($keys) ? $data[$key] : $this->getValueFromNestedArray($data[$key], $keys, $default);
        } elseif (isset($data[$key])) {
            return empty($keys) ? $data[$key] : $default;
        }
        return $default;
    }

    public function validate(array $rules, array $messages = [], array $sanitizers = []): array
    {
        // 1) Start with query params (GET)
        $data = $this->psr->getQueryParams();
        if (!is_array($data)) {
            $data = [];
        }

        // 2) Add parsed body (POST form / multipart; many PSR-7 stacks fill this)
        $body = $this->psr->getParsedBody();
        if (is_array($body) && $body !== []) {
            $data = array_replace($data, $body);
        }

        // 3) If JSON, prefer JSON payload (and avoid @)
        //    Note: do this after parsed body so JSON overrides if present.
        $contentType = strtolower($this->psr->getHeaderLine('Content-Type'));
        if (str_contains($contentType, 'application/json')) {
            $raw = (string) $this->psr->getBody();

            if ($raw !== '') {
                try {
                    $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($json) && $json !== []) {
                        $data = array_replace($data, $json);
                    }
                } catch (\JsonException $e) {
                    // Decide your policy:
                    // - either treat as validation failure:
                    throw new ValidationException(['_json' => ['Invalid JSON body.']]);
                    // - or ignore and continue with parsed-body/query only (less strict)
                }
            }
        }

        // 4) Merge files last (files should not be overridden by scalars)
        foreach ($this->allFiles() as $k => $f) {
            $data[$k] = $f;
        }

        // 5) Sanitizers before validation (your existing behavior)
        if (!empty($sanitizers)) {
            $data = Sanitizer::clean($data, $sanitizers);
        }

        $v = Validator::make($data, $rules, $messages);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }

        return $v->validated();
    }


    public function cache(?string $prefix = null): RequestCache
    {
        if ($this->requestCache === null) $this->requestCache = new RequestCache($prefix);
        return $this->requestCache;
    }

    // accessors for attributes (router params, etc)
    public function set($attribute, $value): void { $this->attributes[$attribute] = $value; }
    public function attr($name) { return $this->attributes[$name] ?? null; }

    // convenience to create a PSR request from raw parts (if you need)
    public function withPsrRequest(ServerRequestInterface $psr): self
    {
        // returns a new wrapper instance that references provided psr
        $new = clone $this;
        $new->psr = $psr;
        $new->basePath = $new->getBasePath();
        return $new;
    }

    // helper: get remote IP
    public function ip(): ?string
    {
        $server = $this->psr->getServerParams();
        return $server['REMOTE_ADDR'] ?? $server['HTTP_X_FORWARDED_FOR'] ?? null;
    }
}
