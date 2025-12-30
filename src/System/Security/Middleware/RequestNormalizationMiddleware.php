<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;

final class RequestNormalizationMiddleware
{
    private const MAX_BODY_BYTES = 1048576; // 1MB

    public function __invoke(Request $req, Response $res, callable $next, array $params): void
    {
        // Allow only standard methods
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['GET','POST','PUT','PATCH','DELETE','HEAD'], true)) {
            $this->deny(405, 'Method Not Allowed');
            return;
        }

        // Enforce body size
        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > self::MAX_BODY_BYTES) {
            $this->deny(413, 'Payload Too Large');
            return;
        }

        // Reject invalid content encodings
        if (!empty($_SERVER['HTTP_CONTENT_ENCODING'])) {
            $this->deny(415, 'Unsupported Content Encoding');
            return;
        }

        $next();
    }

    private function deny(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
}
