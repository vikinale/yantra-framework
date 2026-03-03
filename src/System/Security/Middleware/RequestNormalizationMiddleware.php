<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;

final class RequestNormalizationMiddleware
{
    private const MAX_BODY_BYTES = 1048576; // 1MB

    public function __invoke(Request $req, Response $res, callable $next, array $params): Response
    {
        // Allow only standard methods
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['GET','POST','PUT','PATCH','DELETE','HEAD'], true)) {
            return $this->deny($res, 405, 'Method Not Allowed');
        }

        // Enforce body size
        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > self::MAX_BODY_BYTES) {
            return $this->deny($res, 413, 'Payload Too Large');
        }

        // Reject invalid content encodings
        if (!empty($_SERVER['HTTP_CONTENT_ENCODING'])) {
            return $this->deny($res, 415, 'Unsupported Content Encoding');
        }

        return $next($req, $res);
    }

    private function deny(Response $res, int $code, string $message): Response
    {
        return $res->withStatus($code)
                   ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                   ->text($message, $code);
    }
}
