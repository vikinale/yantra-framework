<?php
declare(strict_types=1);

namespace System\Http;

use System\Utilities\RequestCache;

class Request
{
    protected string $basePath;
    private array $attributes = [];
    private ?RequestCache $requestCache = null;
    private ?array $filesCache = null;
    private ?array $cachedInputs = null;

    public function __construct()
    {
        $this->basePath = $this->getBasePath();
    }

    public static function fromGlobals(): self
    {
        return new self();
    }

    public function only(array $keys, array $defaults = []): array
    {
        $out = [];
        foreach ($keys as $k) {
            $v = $this->input($k, $defaults[$k] ?? null);
            $out[$k] = $v;
        }
        return $out;
    }

    public function header(string $name, $default = null): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return $default;
        }

        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }

        if (strcasecmp($name, 'Content-Type') === 0) {
            if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] !== '') {
                return $_SERVER['CONTENT_TYPE'];
            }
        }
        if (strcasecmp($name, 'Content-Length') === 0) {
            if (isset($_SERVER['CONTENT_LENGTH']) && is_string($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] !== '') {
                return $_SERVER['CONTENT_LENGTH'];
            }
        }

        return $default;
    }

    public function headers(): array
    {
        $out = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if ($headers !== false) {
                return $headers;
            }
        }

        foreach ($_SERVER as $k => $v) {
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
        return $out;
    }

    public function acceptsJson(): bool
    {
        $accept = (string)$this->header('Accept', '');
        $accept = strtolower($accept);
        return $accept !== '' && str_contains($accept, 'application/json');
    }

    public function getMethod(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function getPath(int $index = -1): ?string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = $this->stripBasePath($path);
        
        if ($index >= 0) {
            $parts = explode('/', trim($path, '/'));
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
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '/');
        $basePath = str_replace('\\', '/', dirname($scriptName));
        if ($basePath === '/') $basePath = '';
        return $basePath;
    }

    public function getQuery(?string $key = null, $default = null)
    {
        if ($key === null) return $_GET;
        return $_GET[$key] ?? $default;
    }

    public function param(?string $name, $default = null)
    {
        if ($name === null) return $_GET;
        return $_GET[$name] ?? $default;
    }

    public function all(): array
    {
        return $this->inputs();
    }

    public function inputs(): array
    {
        if ($this->cachedInputs !== null) {
            return $this->cachedInputs;
        }

        $data = $_GET ?? [];

        if (is_array($_POST) && $_POST !== []) {
            $data = array_replace($data, $_POST);
        }

        $contentType = strtolower((string)$this->header('Content-Type'));
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && $raw !== '') {
                try {
                    $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($json) && $json !== []) {
                        $data = array_replace($data, $json);
                    }
                } catch (\JsonException $e) {}
            }
        } else if (in_array($this->getMethod(), ['PUT', 'PATCH', 'DELETE']) && !str_contains($contentType, 'multipart/form-data')) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && $raw !== '') {
                parse_str($raw, $parsed);
                if (is_array($parsed) && $parsed !== []) {
                    $data = array_replace($data, $parsed);
                }
            }
        }

        $this->cachedInputs = $data;
        return $data;
    }

    public function input(string $key, $default = null)
    {
        $data = $this->all();
        if ($key === '') return $data;
        $keys = explode('.', $key);
        return $this->getValueFromNestedArray($data, $keys, $default);
    }

    public function get(string $key, $default = null)
    {
        return $this->input($key, $default);
    }

    public function jsonInput(string $key, $default = null)
    {
        $body = file_get_contents('php://input');
        $decoded = @json_decode($body, true);
        if (!is_array($decoded)) return $default;
        $keys = explode('.', $key);
        return $this->getValueFromNestedArray($decoded, $keys, $default);
    }

    public function getOnly(?string $key = null, $default = null)
    {
        $data = $_GET ?? [];
        if ($key === null || $key === '') return $data;
        $keys = explode('.', $key);
        return $this->getValueFromNestedArray($data, $keys, $default);
    }

    public function postOnly(?string $key = null, $default = null)
    {
        $data = $_POST ?? [];
        if ($key === null || $key === '') return $data;
        $keys = explode('.', $key);
        return $this->getValueFromNestedArray($data, $keys, $default);
    }

    public function inputGet(string $key, $default = null)
    {
        return $this->input($key, $default);
    }

    public function post(?string $key = null, $default = null)
    {
        return $this->postOnly($key, $default);
    }

    public function allFiles(): array
    {
        if ($this->filesCache !== null) {
            return $this->filesCache;
        }

        if (empty($_FILES)) {
            $this->filesCache = [];
            return [];
        }

        $this->filesCache = $this->uploadedFilesFromSuperglobal($_FILES);
        return $this->filesCache;
    }

    private function uploadedFilesFromSuperglobal(array $files): array
    {
        $out = [];
        foreach ($files as $field => $spec) {
            if (isset($spec['name']) && !is_array($spec['name'])) {
                if ((int)($spec['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $out[$field] = new UploadedFile(
                    (string)($spec['tmp_name'] ?? ''),
                    (int)($spec['size'] ?? 0),
                    (int)($spec['error'] ?? UPLOAD_ERR_OK),
                    (string)($spec['name'] ?? ''),
                    (string)($spec['type'] ?? '')
                );
                continue;
            }
            $out[$field] = $this->normalizeFilesSpec($spec);
        }
        return $out;
    }

    private function normalizeFilesSpec(array $spec): array
    {
        $names = $spec['name'] ?? [];
        $out = [];
        foreach ($names as $k => $v) {
            if (is_array($v)) {
                $out[$k] = $this->normalizeFilesSpec([
                    'name'     => $spec['name'][$k] ?? [],
                    'type'     => $spec['type'][$k] ?? [],
                    'tmp_name' => $spec['tmp_name'][$k] ?? [],
                    'error'    => $spec['error'][$k] ?? [],
                    'size'     => $spec['size'][$k] ?? [],
                ]);
                continue;
            }
            $err = (int)($spec['error'][$k] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[$k] = new UploadedFile(
                (string)($spec['tmp_name'][$k] ?? ''),
                (int)($spec['size'][$k] ?? 0),
                $err,
                (string)($spec['name'][$k] ?? ''),
                (string)($spec['type'][$k] ?? '')
            );
        }
        return $out;
    }

    public function storeUploadedFile(UploadedFile $file, string $destinationDir, ?string $filename = null): string
    {
        $safeName = $filename ?: $this->safeUploadName($file->getClientFilename() ?: 'file');
        $target = rtrim($destinationDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;
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

    private function file(string $key): ?UploadedFile
    {
        $files = $this->allFiles();
        return current($this->findFileInArray($files, explode('.', $key)));
    }

    private function findFileInArray(array $array, array $keys): array
    {
        $key = array_shift($keys);
        if (!isset($array[$key])) return [];
        if (empty($keys) && $array[$key] instanceof UploadedFile) return [$array[$key]];
        if (is_array($array[$key])) return $this->findFileInArray($array[$key], $keys);
        return [];
    }

    public function inputFileBase64(string $name, $default = null)
    {
        $file = $this->file($name);
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) return $default;
        $content = $file->getStreamContent();
        return $content !== '' ? base64_encode($content) : $default;
    }

    public function inputFileBlob(string $name, $default = null)
    {
        $file = $this->file($name);
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) return $default;
        $content = $file->getStreamContent();
        return $content !== '' ? $content : $default;
    }

    public function isAjax(): bool
    {
        return strcasecmp((string)$this->header('X-Requested-With'), 'XMLHttpRequest') === 0;
    }

    public function isPost(): bool { return $this->getMethod() === 'POST'; }
    public function isDelete(): bool { return $this->getMethod() === 'DELETE'; }
    public function isPut(): bool { return $this->getMethod() === 'PUT'; }
    public function isGet(): bool { return $this->getMethod() === 'GET'; }

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

    public function cache(?string $prefix = null): RequestCache
    {
        if ($this->requestCache === null) $this->requestCache = new RequestCache($prefix);
        return $this->requestCache;
    }

    public function set(string $attribute, mixed $value): void { $this->attributes[$attribute] = $value; }
    public function attr(string $name): mixed { return $this->attributes[$name] ?? null; }

    public function userAgent(?string $default = null): ?string
    {
        return $this->header('User-Agent', $default);
    }

    public function ip(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    public function trustedProxyIp(): ?string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if (is_string($forwarded) && $forwarded !== '') {
            $ips = array_map('trim', explode(',', $forwarded));
            $ip = $ips[0] ?? null;
            if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}
