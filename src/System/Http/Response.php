<?php
declare(strict_types=1);

namespace System\Http;

use RuntimeException;
use System\View\ViewRenderer;

final class Response
{
    private int $statusCode;
    private string $reasonPhrase;
    private string $protocolVersion = '1.1';
    private array $headers = [];
    private string $body;
    private ?ViewRenderer $view = null;
    private ?string $filePath = null; // for file downloads

    public function __construct(int $status = 200, array $headers = [], string $body = '')
    {
        $this->statusCode = $status;
        $this->reasonPhrase = HttpStatus::phrase($status);
        $this->body = $body;

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                $this->headers[strtolower($name)] = ['name' => $name, 'values' => $value];
            } else {
                $this->headers[strtolower($name)] = ['name' => $name, 'values' => [(string)$value]];
            }
        }
    }

    public function setViewRenderer(ViewRenderer $view): void
    {
        $this->view = $view;
    }

    public function getProtocolVersion(): string { return $this->protocolVersion; }

    public function getHeaders(): array
    {
        $out = [];
        foreach ($this->headers as $header) {
            $out[$header['name']] = $header['values'];
        }
        return $out;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $key = strtolower($name);
        return $this->headers[$key]['values'] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): self
    {
        $clone = clone $this;
        $key = strtolower($name);
        $clone->headers[$key] = [
            'name' => $name,
            'values' => is_array($value) ? array_map('strval', $value) : [(string)$value]
        ];
        return $clone;
    }

    public function withAddedHeader(string $name, $value): self
    {
        $clone = clone $this;
        $key = strtolower($name);
        if (!isset($clone->headers[$key])) {
            $clone->headers[$key] = ['name' => $name, 'values' => []];
        }
        $newVals = is_array($value) ? array_map('strval', $value) : [(string)$value];
        $clone->headers[$key]['values'] = array_merge($clone->headers[$key]['values'], $newVals);
        return $clone;
    }

    public function withoutHeader(string $name): self
    {
        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);
        return $clone;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): self
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = ($reasonPhrase !== '') ? $reasonPhrase : HttpStatus::phrase($code);
        return $clone;
    }

    public function withProtocolVersion(string $version): self
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;
        return $clone;
    }

    public function withBody(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function getBody(): string { return $this->body; }

    public function getViewRenderer(): ?ViewRenderer
    {
        return $this->view;
    }

    public function getStatusCode(): int { return $this->statusCode; }

    public function getReasonPhrase(): string { return $this->reasonPhrase; }

    public function header(string $name, string $value): self
    {
        return $this->withHeader($name, $value);
    }

    public function headers(array $headers): self
    {
        $new = clone $this;
        foreach ($headers as $k => $v) {
            $new = $new->withHeader((string)$k, (string)$v);
        }
        return $new;
    }

    public function statusWithTextHeaders(int $code, ?string $reasonPhrase = null): self
    {
        $phrase = ($reasonPhrase !== null && $reasonPhrase !== '')
            ? $reasonPhrase
            : HttpStatus::phrase($code);

        return $this->withStatus($code, $phrase)
            ->withHeader('X-Status-Code', (string)$code)
            ->withHeader('X-Status-Text', $phrase);
    }

    public function html(string $html, int $status = 200): self
    {
        $new = $this->statusWithTextHeaders($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($html);

        $size = strlen($html);
        return $new->withHeader('Content-Length', (string)$size);
    }

    public function text(string $text, int $status = 200): self
    {
        $new = $this->statusWithTextHeaders($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody($text);

        $size = strlen($text);
        return $new->withHeader('Content-Length', (string)$size);
    }

    public function json(mixed $data, int $status = 200, int $jsonOptions = JSON_UNESCAPED_UNICODE): self
    {
        $payload = json_encode($data, $jsonOptions);
        if ($payload === false) {
            $payload = json_encode(
                ['error' => 'json_encode_failed', 'message' => json_last_error_msg()],
                JSON_UNESCAPED_UNICODE
            ) ?: '{"error":"json_encode_failed"}';
            $status = 500;
        }

        $new = $this->statusWithTextHeaders($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($payload);

        $size = strlen($payload);
        return $new->withHeader('Content-Length', (string)$size);
    }

    public function redirect(string $url, int $code = 302): self
    {
        $url = str_replace(["\r", "\n"], '', $url);

        if (preg_match('/^(javascript:|data:)/i', $url)) {
            throw new RuntimeException('Invalid redirect URL scheme.');
        }

        if (!preg_match('#^https?://#i', $url) && $url !== '' && $url[0] !== '/') {
            $url = '/' . ltrim($url, '/');
        }

        return $this->statusWithTextHeaders($code)
            ->withHeader('Location', $url);
    }

    public function file(string $filePath, ?string $downloadName = null, ?string $mime = null): self
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("File not found or unreadable: {$filePath}");
        }

        $size = filesize($filePath);
        $new = clone $this;
        $new = $new->statusWithTextHeaders(200)
            ->withHeader('Content-Type', $mime ?? $this->detectMime($filePath));
            
        $new->filePath = $filePath; // Don't block memory for big files, just send them natively

        if ($size !== false) {
            $new = $new->withHeader('Content-Length', (string)$size);
        }

        if ($downloadName !== null && $downloadName !== '') {
            $safe = basename($downloadName);
            $safe = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $safe) ?? 'download';
            $new = $new->withHeader('Content-Disposition', 'attachment; filename="' . $safe . '"');
        }

        return $new;
    }

    public function base64ToFileDownload(string $base64Data, string $filename): self
    {
        if (!preg_match('/^data:(.*?);base64,/', $base64Data, $m)) {
            throw new RuntimeException('Invalid base64 data format.');
        }

        $mime = $m[1];
        $payload = substr($base64Data, strpos($base64Data, ',') + 1);

        $binary = base64_decode($payload, true);
        if ($binary === false) {
            throw new RuntimeException('Base64 decode failed.');
        }

        $ext = $this->extensionFromMime($mime) ?? 'bin';
        $fullName = $filename . '.' . $ext;

        $safeName = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', basename($fullName)) ?? 'download';

        $new = $this->statusWithTextHeaders(200)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->withBody($binary);

        $size = strlen($binary);
        return $new->withHeader('Content-Length', (string)$size);
    }

    public function emit(): void
    {
        if (!headers_sent()) {
            $code = $this->getStatusCode();
            $phrase = $this->getReasonPhrase();
            
            header(sprintf('HTTP/%s %d %s', $this->getProtocolVersion(), $code, $phrase), true, $code);

            foreach ($this->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }

        if ($this->filePath !== null) {
            readfile($this->filePath);
        } else {
            echo $this->getBody();
        }
    }

    public function emitAndExit(): never
    {
        $this->emit();
        exit;
    }

    private function detectMime(string $path): string
    {
        if (function_exists('mime_content_type')) {
            $m = @mime_content_type($path);
            if ($m !== false) {
                return $m;
            }
        }
        return 'application/octet-stream';
    }

    private function extensionFromMime(string $mime): ?string
    {
        static $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'text/plain' => 'txt',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        ];
        return $map[$mime] ?? null;
    }

    public function view(string $name, array $data = [], ?string $layout = 'layouts/main', int $status = 200): self
    {
        if ($this->view === null) {
            throw new RuntimeException('ViewRenderer not set. Call setViewRenderer() before rendering views.');
        }
        $html = $this->view->render($name, $data, $layout);
        return $this->html($html, $status);
    }

    public function noStore(): self
    {
        $new = clone $this;
        return $new->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    public function cachePublic(int $seconds): self
    {
        $seconds = max(0, $seconds);

        $new = clone $this;
        return $new->withHeader('Cache-Control', 'public, max-age=' . $seconds)
            ->withHeader('Expires', gmdate('D, d M Y H:i:s \G\M\T', time() + $seconds));
    }

    public function redirectSeeOther(string $url): self
    {
        return $this->redirect($url, 303);
    }
}
