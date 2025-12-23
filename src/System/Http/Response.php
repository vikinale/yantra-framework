<?php
declare(strict_types=1);

namespace System\Http;

use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use System\View\ViewRenderer;

final class Response implements ResponseInterface
{
    private ResponseInterface $psr;
    private Psr17Factory $factory;
    private ViewRenderer $view;

    /**
     * @param ResponseInterface|null $psr Wrap existing PSR-7 response if provided.
     * @param int $status Used only when $psr is null.
     * @param array<string, string|string[]> $headers Used only when $psr is null.
     * @param string|StreamInterface|null $body Used only when $psr is null.
     */
    public function __construct(?ResponseInterface $psr = null, int $status = 200, array $headers = [], string|StreamInterface|null $body = null)
    {
        $this->factory = new Psr17Factory();

        if ($psr !== null) {
            $this->psr = $psr;
            return;
        }

        $resp = $this->factory->createResponse($status);

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $resp = $resp->withAddedHeader($name, (string)$v);
                }
            } else {
                $resp = $resp->withHeader($name, (string)$value);
            }
        }

        if ($body !== null) {
            $resp = $resp->withBody($this->toStream($body));
        }

        $this->psr = $resp;
                // default views path; adjust if you store in Config
        $this->view = $view ?? new ViewRenderer([APPPATH . '/Views']);
    }

    // -----------------------
    // PSR-7: delegate
    // -----------------------

    public function getProtocolVersion(): string { return $this->psr->getProtocolVersion(); }

    public function withProtocolVersion($version): ResponseInterface
    {
        $new = clone $this;
        $new->psr = $this->psr->withProtocolVersion($version);
        return $new;
    }

    public function getHeaders(): array { return $this->psr->getHeaders(); }
    public function hasHeader($name): bool { return $this->psr->hasHeader($name); }
    public function getHeader($name): array { return $this->psr->getHeader($name); }
    public function getHeaderLine($name): string { return $this->psr->getHeaderLine($name); }

    public function withHeader($name, $value): ResponseInterface
    {
        $new = clone $this;
        $new->psr = $this->psr->withHeader($name, $value);
        return $new;
    }

    public function withAddedHeader($name, $value): ResponseInterface
    {
        $new = clone $this;
        $new->psr = $this->psr->withAddedHeader($name, $value);
        return $new;
    }

    public function withoutHeader($name): ResponseInterface
    {
        $new = clone $this;
        $new->psr = $this->psr->withoutHeader($name);
        return $new;
    }

    public function getBody(): StreamInterface { return $this->psr->getBody(); }

    public function withBody(StreamInterface $body): ResponseInterface
    {
        $new = clone $this;
        $new->psr = $this->psr->withBody($body);
        return $new;
    }

    public function getStatusCode(): int { return $this->psr->getStatusCode(); }

    public function withStatus($code, $reasonPhrase = ''): ResponseInterface
    {
        $new = clone $this;
        $new->psr = $this->psr->withStatus($code, $reasonPhrase);
        return $new;
    }

    public function getReasonPhrase(): string { return $this->psr->getReasonPhrase(); }

    // -----------------------
    // Convenience helpers (immutable)
    // -----------------------

    public function html(string $html, int $status = 200): ResponseInterface
    {
        $stream = $this->factory->createStream($html);

        $new = clone $this;
        $new->psr = $this->psr
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($stream);

        // Content-Length is optional for PSR-7; safe when size known
        $size = $stream->getSize();
        if ($size !== null) {
            $new->psr = $new->psr->withHeader('Content-Length', (string)$size);
        }

        return $new;
    }

    public function text(string $text, int $status = 200): ResponseInterface
    {
        $stream = $this->factory->createStream($text);

        $new = clone $this;
        $new->psr = $this->psr
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody($stream);

        $size = $stream->getSize();
        if ($size !== null) {
            $new->psr = $new->psr->withHeader('Content-Length', (string)$size);
        }

        return $new;
    }

    public function json(mixed $data, int $status = 200, int $jsonOptions = JSON_UNESCAPED_UNICODE): ResponseInterface
    {
        $payload = json_encode($data, $jsonOptions);
        if ($payload === false) {
            $payload = json_encode(
                ['error' => 'json_encode_failed', 'message' => json_last_error_msg()],
                JSON_UNESCAPED_UNICODE
            ) ?: '{"error":"json_encode_failed"}';
            $status = 500;
        }

        $stream = $this->factory->createStream($payload);

        $new = clone $this;
        $new->psr = $this->psr
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($stream);

        $size = $stream->getSize();
        if ($size !== null) {
            $new->psr = $new->psr->withHeader('Content-Length', (string)$size);
        }

        return $new;
    }

    public function redirect(string $url, int $code = 302): ResponseInterface
    {
        $url = str_replace(["\r", "\n"], '', $url);

        // Reject javascript:/data: etc.
        if (preg_match('/^(javascript:|data:)/i', $url)) {
            throw new RuntimeException('Invalid redirect URL scheme.');
        }

        // Allow absolute http(s) or absolute-path (/foo) or relative (foo)
        if (!preg_match('#^https?://#i', $url) && $url !== '' && $url[0] !== '/') {
            $url = '/' . ltrim($url, '/');
        }

        $new = clone $this;
        $new->psr = $this->psr->withStatus($code)->withHeader('Location', $url);
        return $new;
    }

    public function file(string $filePath, ?string $downloadName = null, ?string $mime = null): ResponseInterface
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("File not found or unreadable: {$filePath}");
        }

        $size = filesize($filePath);
        $stream = $this->createStreamFromFile($filePath);

        $new = clone $this;
        $new->psr = $this->psr
            ->withStatus(200)
            ->withHeader('Content-Type', $mime ?? $this->detectMime($filePath))
            ->withBody($stream);

        if ($size !== false) {
            $new->psr = $new->psr->withHeader('Content-Length', (string)$size);
        }

        if ($downloadName !== null && $downloadName !== '') {
            $safe = basename($downloadName);
            $new->psr = $new->psr->withHeader('Content-Disposition', 'attachment; filename="' . $safe . '"');
        }

        return $new;
    }

    public function base64ToFileDownload(string $base64Data, string $filename): ResponseInterface
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

        $stream = $this->factory->createStream($binary);

        $new = clone $this;
        $new->psr = $this->psr
            ->withStatus(200)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($fullName) . '"')
            ->withBody($stream);

        $size = $stream->getSize();
        if ($size !== null) {
            $new->psr = $new->psr->withHeader('Content-Length', (string)$size);
        }

        return $new;
    }

    // -----------------------
    // Emit (side-effecting)
    // -----------------------

    public function emit(): void
    {
        $resp = $this->psr;

        if (!headers_sent() && class_exists(SapiEmitter::class)) {
            try {
                (new SapiEmitter())->emit($resp);
                return;
            } catch (\Throwable $e) {
                error_log('[Core\Response::emit] SapiEmitter failed: ' . $e->getMessage());
            }
        }

        if (!headers_sent()) {
            http_response_code($resp->getStatusCode());
            foreach ($resp->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }

        $body = $resp->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read(8192);
        }
    }

    public function emitAndExit(): never
    {
        $this->emit();
        exit;
    }

    // -----------------------
    // Utilities
    // -----------------------

    public function getPsr7(): ResponseInterface
    {
        return $this->psr;
    }

    private function toStream(string|StreamInterface $body): StreamInterface
    {
        return is_string($body) ? $this->factory->createStream($body) : $body;
    }

    private function createStreamFromFile(string $path): StreamInterface
    {
        $resource = fopen($path, 'rb');
        if ($resource === false) {
            throw new RuntimeException("Unable to open file: {$path}");
        }
        return Stream::create($resource);
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
        $html = $this->view->render($name, $data, $layout);
        return $this->html($html, $status);
    }

}
