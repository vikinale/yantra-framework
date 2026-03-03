<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;

final class SecurityHeadersMiddleware
{
    public function __construct(
        private string $profile = 'web'
    ) {}

    public function __invoke(Request $req, Response $res, callable $next, array $params): Response
    {
        // Baseline headers
        $res = $res->withHeader('X-Content-Type-Options', 'nosniff')
                   ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                   ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Note: Content-Security-Policy is NOT set here.
        // Apps should define their own CSP via config or custom middleware,
        // as CSP requirements vary widely between applications.

        // HTTPS hardening
        if ($this->isHttps()) {
            $res = $res->withHeader('Strict-Transport-Security', 'max-age=15552000; includeSubDomains');
        }

        return $next($req, $res);
    }

    private function isHttps(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        );
    }
}
