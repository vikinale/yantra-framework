<?php
namespace System\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
use System\Utilities\RequestCache;
use System\Utilities\Validator;
use System\Utilities\Sanitizer;
use System\Utilities\ValidationException;
use RuntimeException;
use System\View\ViewRenderer;

class Request
{
    protected ServerRequestInterface $psr;    // underlying PSR-7 request
    protected string $basePath;
    private array $attributes = [];
    private ?RequestCache $requestCache = null;
    private Psr17Factory $psrFactory;
    private ViewRenderer $view;

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
        // merge parsed body and query for legacy behavior
        $parsed = $this->psr->getParsedBody();
        if (!is_array($parsed)) $parsed = [];
        return array_merge($this->psr->getQueryParams(), $parsed, $_REQUEST);
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
    public function allFiles(): array
    {
        $out = [];
        foreach ($this->psr->getUploadedFiles() as $key => $uploaded) {
            $out[$key] = $this->convertUploadedFile($uploaded);
        }
        return $out;
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

    // validation (same logic but using PSR-request body)
    public function validate(array $rules, array $messages = [], array $sanitizers = []): array
    {
        // get parsed body if JSON or form; fallback to $_REQUEST
        $body = $this->psr->getParsedBody();
        if (!is_array($body)) $body = [];
        // JSON raw fallback
        $raw = (string)$this->psr->getBody();
        $json = @json_decode($raw, true);
        if (is_array($json) && count($json) > 0) {
            $data = array_merge($_REQUEST, $json);
        } else {
            $data = array_merge($_REQUEST, $body);
        }

        foreach ($this->allFiles() as $k => $f) {
            $data[$k] = $f;
        }

        if (!empty($sanitizers)) $data = Sanitizer::clean($data, $sanitizers);

        $v = Validator::make($data, $rules, $messages);
        if ($v->fails()) throw new ValidationException($v->errors());
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
