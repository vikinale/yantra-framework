<?php
namespace System\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use RuntimeException;

/**
 * Immutable PSR-7 Response adapter.
 *
 * - Implements Psr\Http\Message\ResponseInterface.
 * - All "with*" methods return a new Core\Response (immutable).
 * - Convenience helpers (json, text, file, redirect, base64ToFileDownload) are immutable.
 * - Use ->emit() or ->emitAndExit() to send to client.
 */
final class Response implements ResponseInterface
{
    private ResponseInterface $psr;
    private Psr17Factory $factory;

    /**
     * Accepts either an existing PSR-7 ResponseInterface or builds a new one.
     *
     * @param ResponseInterface|null $psr
     * @param int $status
     * @param array $headers
     * @param string|StreamInterface|null $body
     */
    public function __construct(?ResponseInterface $psr = null, int $status = 200, array $headers = [], $body = null)
    {
        $this->factory = new Psr17Factory();

        if ($psr !== null) {
            $this->psr = $psr;
        } else {
            $this->psr = $this->factory->createResponse($status);
            foreach ($headers as $k => $v) {
                if (is_array($v)) {
                    foreach ($v as $val) {
                        $this->psr = $this->psr->withAddedHeader($k, (string)$val);
                    }
                } else {
                    $this->psr = $this->psr->withHeader($k, (string)$v);
                }
            }
            if ($body !== null) {
                $stream = is_string($body) ? $this->factory->createStream($body)
                    : ($body instanceof StreamInterface ? $body : $this->factory->createStream((string)$body));
                $this->psr = $this->psr->withBody($stream);
            }
        }
    }

    // -----------------------
    // PSR-7 interface — delegate and return new Core\Response where required
    // -----------------------

    public function getProtocolVersion(): string
    {
        return $this->psr->getProtocolVersion();
    }

    public function withProtocolVersion($version): ResponseInterface
    {
        $new = clone $this;
        $new->psr = $this->psr->withProtocolVersion($version);
        return $new;
    }

    public function getHeaders(): array
    {
        return $this->psr->getHeaders();
    }

    public function hasHeader($name): bool
    {
        return $this->psr->hasHeader($name);
    }

    public function getHeader($name): array
    {
        return $this->psr->getHeader($name);
    }

    public function getHeaderLine($name): string
    {
        return $this->psr->getHeaderLine($name);
    }

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

    public function getBody(): StreamInterface
    {
        return $this->psr->getBody();
    }

    public function withBody(StreamInterface $body): ResponseInterface
    {
        $new = clone $this;
        $new->psr = $this->psr->withBody($body);
        return $new;
    }

    public function getStatusCode(): int
    {
        return $this->psr->getStatusCode();
    }

    public function withStatus($code, $reasonPhrase = ''): ResponseInterface
    {
        $new = clone $this;
        $new->psr = $this->psr->withStatus($code, $reasonPhrase);
        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->psr->getReasonPhrase();
    }

    // -----------------------
    // Immutable convenience helpers
    // All return ResponseInterface (Core\Response)
    // -----------------------

    public function json(mixed $data, int $status = 200, int $jsonOptions = JSON_UNESCAPED_UNICODE): ResponseInterface
    {
        $payload = json_encode($data, $jsonOptions);
        if ($payload === false) {
            $payload = json_encode(['error' => 'json_encode_failed', 'message' => json_last_error_msg()], JSON_UNESCAPED_UNICODE);
            $status = 500;
        }

        $stream = $this->factory->createStream($payload);
        $new = clone $this;
        $new->psr = $this->psr
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Length', (string)$stream->getSize())
            ->withStatus($status);

        return $new;
    }

    public function text(string $text, int $status = 200): ResponseInterface
    {
        $stream = $this->factory->createStream($text);
        $new = clone $this;
        $new->psr = $this->psr
            ->withBody($stream)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Content-Length', (string)$stream->getSize())
            ->withStatus($status);
        return $new;
    }

    /**
     * Serve a file. Uses a stream resource (no full-read into memory).
     * Returns new immutable Response.
     *
     * @param string $filePath
     * @param string|null $downloadName
     * @throws RuntimeException
     */
    public function file(string $filePath, ?string $downloadName = null): ResponseInterface
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("File not found or unreadable: {$filePath}");
        }

        $size = filesize($filePath);
        $stream = $this->createStreamFromFile($filePath);

        $new = clone $this;
        $new->psr = $this->psr
            ->withBody($stream)
            ->withHeader('Content-Type', $this->detectMime($filePath))
            ->withHeader('Content-Length', (string)$size)
            ->withStatus(200);

        if ($downloadName) {
            $new->psr = $new->psr->withHeader('Content-Disposition', 'attachment; filename="' . basename($downloadName) . '"');
        }

        return $new;
    }

    public function redirect(string $url, int $code = 302): ResponseInterface
    {
        $url = str_replace(["\r", "\n"], '', $url);
        if (!preg_match('#^https?://#i', $url) && $url !== '' && $url[0] !== '/') {
            $url = '/' . ltrim($url, '/');
        }

        $new = clone $this;
        $new->psr = $this->psr->withHeader('Location', $url)->withStatus($code);
        return $new;
    }

    /**
     * Convert base64 data to a download response (immutable)
     *
     * @param string $base64Data
     * @param string $filename base name without extension
     * @return ResponseInterface
     */
    public function base64ToFileDownload(string $base64Data, string $filename): ResponseInterface
    {
        if (!preg_match('/^data:(.*?);base64,/', $base64Data, $m)) {
            throw new RuntimeException('Invalid base64 data format.');
        }
        $mime = $m[1];
        $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);

        $binary = base64_decode($base64Data, true);
        if ($binary === false) {
            throw new RuntimeException('Base64 decode failed.');
        }

        $ext = $this->extensionFromMime($mime) ?? 'bin';
        $fullName = $filename . '.' . $ext;

        $stream = $this->factory->createStream($binary);
        $new = clone $this;
        $new->psr = $this->psr
            ->withBody($stream)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fullName . '"')
            ->withHeader('Content-Length', (string)$stream->getSize())
            ->withStatus(200);

        return $new;
    }

    // -----------------------
    // Emit helpers (side-effecting). These do not mutate the object.
    // -----------------------

    /**
     * Emit this response to the client.
     */
        public function emit(): void
        {
            $resp = $this->psr; // underlying PSR-7

            // If no previous output and Laminas emitter available, prefer it
            if (!headers_sent() && class_exists(\Laminas\HttpHandlerRunner\Emitter\SapiEmitter::class)) {
                try {
                    $emitter = new \Laminas\HttpHandlerRunner\Emitter\SapiEmitter();
                    $emitter->emit($resp);
                    return;
                } catch (\Throwable $e) {
                    // Fall through to fallback emitter (log optionally)
                    error_log('[Response::emit] SapiEmitter failed: ' . $e->getMessage());
                }
            }

            // FALLBACK EMITTER: If headers already sent or SapiEmitter not available
            // Attempt to send headers that are still possible; then echo body.
            // Use get_included_files info for debugging header-sent location.
            if (headers_sent($file, $line)) {
                // Headers already sent — don't call SapiEmitter.
                // Optionally log where output started
                error_log("[Response::emit] headers already sent in {$file}:{$line}. Falling back to inline output.");
            } else {
                // Try to send headers via header() safely
                http_response_code($resp->getStatusCode());
                foreach ($resp->getHeaders() as $name => $values) {
                    // If header already present in PHP env, header() will append/replace accordingly
                    foreach ($values as $value) {
                        header(sprintf('%s: %s', $name, $value), false);
                    }
                }
            }

            // Now stream the body (seek if possible)
            $body = $resp->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            while (!$body->eof()) {
                echo $body->read(8192);
                if (function_exists('flush')) {
                    flush();
                }
            }
        }

    /**
     * Emit and exit.
     *
     * @return never
     */
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

    protected function createStreamFromFile(string $path): StreamInterface
    {
        $resource = fopen($path, 'rb');
        if ($resource === false) {
            throw new RuntimeException("Unable to open file {$path}");
        }
        return Stream::create($resource);
    }

    protected function detectMime(string $path): string
    {
        if (function_exists('mime_content_type')) {
            $m = @mime_content_type($path);
            if ($m !== false) return $m;
        }
        return 'application/octet-stream';
    }

    protected function extensionFromMime(string $mime): ?string
    {
        $map = [
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
}
