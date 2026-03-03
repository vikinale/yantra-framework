<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;
use Throwable;

final class AuditMiddleware
{
    public function __construct(
        private string $channel = 'security'
    ) {}

    public function __invoke(Request $req, Response $res, callable $next, array $params): Response
    {
        $rid = $this->requestId();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path   = $_SERVER['REQUEST_URI'] ?? '/';
        $ip     = $_SERVER['REMOTE_ADDR'] ?? '';

        $this->log('request', [
            'rid' => $rid,
            'method' => $method,
            'path' => $path,
            'ip' => $ip,
        ]);

        try {
            $response = $next($req, $res);

            $status = $response->getStatusCode();
            $this->log('response', [
                'rid' => $rid,
                'status' => $status,
            ]);
            
            return $response;
        } catch (Throwable $e) {
            $this->log('exception', [
                'rid' => $rid,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    public function logEvent(string $event, array $ctx = []): void
    {
        $ctx['rid'] = $ctx['rid'] ?? $this->requestId();
        $this->log($event, $ctx);
    }

    private function requestId(): string
    {
        static $rid = null;
        if ($rid !== null) return $rid;

        // Always generate server-side ID — never trust client header as canonical
        $rid = bin2hex(random_bytes(8));
        return $rid;
    }

    /**
     * Get client-supplied request ID for logging context (untrusted).
     */
    private function clientRequestId(): ?string
    {
        $val = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        return is_string($val) && $val !== '' ? substr($val, 0, 128) : null;
    }

    private function log(string $event, array $ctx): void
    {
        $line = json_encode([
            'ts' => date('c'),
            'channel' => $this->channel,
            'event' => $event,
            'ctx' => $ctx,
        ], JSON_UNESCAPED_SLASHES);

        // No files: use error_log
        error_log($line ?: ('{"event":"audit_encode_failed"}'));
    }
}
