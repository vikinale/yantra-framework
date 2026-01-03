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

        // Create with status + reason phrase (best-effort)
        $resp = $this->factory->createResponse($status, HttpStatus::phrase($status));

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
    }

    public function setViewRenderer(ViewRenderer $view): void
    {
        $this->view = $view;
    }

    // -----------------------
    // PSR-7: delegate
    // -----------------------

    public function getProtocolVersion(): string { return $this->psr->getProtocolVersion(); }

    public function getHeaders(): array { return $this->psr->getHeaders(); }
    public function hasHeader($name): bool { return $this->psr->hasHeader($name); }
    public function getHeader($name): array { return $this->psr->getHeader($name); }
    public function getHeaderLine($name): string { return $this->psr->getHeaderLine($name); }

    public function withHeader($name, $value): self
    {
        $clone = clone $this;
        $clone->psr = $this->psr->withHeader($name, $value);
        return $clone;
    }

    public function withAddedHeader($name, $value): self
    {
        $clone = clone $this;
        $clone->psr = $this->psr->withAddedHeader($name, $value);
        return $clone;
    }

    public function withoutHeader($name): self
    {
        $clone = clone $this;
        $clone->psr = $this->psr->withoutHeader($name);
        return $clone;
    }

    public function withStatus($code, $reasonPhrase = ''): self
    {
        $clone = clone $this;

        $phrase = ($reasonPhrase !== null && $reasonPhrase !== '')
            ? (string)$reasonPhrase
            : HttpStatus::phrase((int)$code);

        $clone->psr = $this->psr->withStatus((int)$code, $phrase);
        return $clone;
    }

    public function withProtocolVersion($version): self
    {
        $clone = clone $this;
        $clone->psr = $this->psr->withProtocolVersion($version);
        return $clone;
    }

    public function withBody(StreamInterface $body): self
    {
        $clone = clone $this;
        $clone->psr = $this->psr->withBody($body);
        return $clone;
    }

    public function getBody(): StreamInterface { return $this->psr->getBody(); }

    public function getViewRenderer(): ViewRenderer
    {
        return $this->view;
    }

    public function getStatusCode(): int { return $this->psr->getStatusCode(); }

    public function getReasonPhrase(): string { return $this->psr->getReasonPhrase(); }

    // -----------------------
    // Convenience helpers (immutable)
    // -----------------------

    /**
     * Convenience alias for withHeader (nice for fluent code).
     */
    public function header(string $name, string $value): self
    {
        return $this->withHeader($name, $value);
    }

    /**
     * Bulk set headers.
     *
     * @param array<string, string> $headers
     */
    public function headers(array $headers): self
    {
        $new = clone $this;
        foreach ($headers as $k => $v) {
            $new->psr = $new->psr->withHeader((string)$k, (string)$v);
        }
        return $new;
    }

    /**
     * Set status + reason phrase and include debug/status headers.
     * These headers are non-standard but extremely useful for SPA clients/logging.
     */
    public function statusWithTextHeaders(int $code, ?string $reasonPhrase = null): self
    {
        $phrase = ($reasonPhrase !== null && $reasonPhrase !== '')
            ? $reasonPhrase
            : HttpStatus::phrase($code);

        $new = clone $this;
        $new->psr = $this->psr
            ->withStatus($code, $phrase)
            ->withHeader('X-Status-Code', (string)$code)
            ->withHeader('X-Status-Text', $phrase);

        return $new;
    }

    public function html(string $html, int $status = 200): self
    {
        $stream = $this->factory->createStream($html);

        $new = $this->statusWithTextHeaders($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($stream);

        $size = $stream->getSize();
        if ($size !== null) {
            $new = $new->withHeader('Content-Length', (string)$size);
        }

        return $new;
    }

    public function text(string $text, int $status = 200): self
    {
        $stream = $this->factory->createStream($text);

        $new = $this->statusWithTextHeaders($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody($stream);

        $size = $stream->getSize();
        if ($size !== null) {
            $new = $new->withHeader('Content-Length', (string)$size);
        }

        return $new;
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

        $stream = $this->factory->createStream($payload);

        $new = $this->statusWithTextHeaders($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($stream);

        $size = $stream->getSize();
        if ($size !== null) {
            $new = $new->withHeader('Content-Length', (string)$size);
        }

        return $new;
    }

    public function redirect(string $url, int $code = 302): self
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

        $new = $this->statusWithTextHeaders($code)
            ->withHeader('Location', $url);

        return $new;
    }

    public function file(string $filePath, ?string $downloadName = null, ?string $mime = null): self
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("File not found or unreadable: {$filePath}");
        }

        $size = filesize($filePath);
        $stream = $this->createStreamFromFile($filePath);

        $new = $this->statusWithTextHeaders(200)
            ->withHeader('Content-Type', $mime ?? $this->detectMime($filePath))
            ->withBody($stream);

        if ($size !== false) {
            $new = $new->withHeader('Content-Length', (string)$size);
        }

        if ($downloadName !== null && $downloadName !== '') {
            $safe = basename($downloadName);
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

        $stream = $this->factory->createStream($binary);

        $new = $this->statusWithTextHeaders(200)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($fullName) . '"')
            ->withBody($stream);

        $size = $stream->getSize();
        if ($size !== null) {
            $new = $new->withHeader('Content-Length', (string)$size);
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
            // Ensure reason phrase is present in the status line when possible
            $code = $resp->getStatusCode();
            $phrase = $resp->getReasonPhrase();
            if ($phrase === '') {
                $phrase = HttpStatus::phrase($code);
            }

            // Use the 3-arg header() form to send full status line when not using emitter.
            // This is optional; the emitter already handles status line correctly.
            header(sprintf('HTTP/%s %d %s', $resp->getProtocolVersion(), $code, $phrase), true, $code);

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

    /**
     * Prevent any caching (recommended for login/logout/auth).
     */
    public function noStore(): self
    {
        $new = clone $this;
        $new->psr = $new->psr
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');

        return $new;
    }

    /**
     * Public cache headers (optional helper).
     */
    public function cachePublic(int $seconds): self
    {
        $seconds = max(0, $seconds);

        $new = clone $this;
        $new->psr = $new->psr
            ->withHeader('Cache-Control', 'public, max-age=' . $seconds)
            ->withHeader('Expires', gmdate('D, d M Y H:i:s \G\M\T', time() + $seconds));

        return $new;
    }

    /**
     * Redirect that is correct for POST/PUT/PATCH -> GET patterns.
     * Use 303 by default (See Other).
     */
    public function redirectSeeOther(string $url): self
    {
        return $this->redirect($url, 303);
    }

}
