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

    public function __invoke(Request $req, Response $res, callable $next, array $params): void
    {
        // Baseline headers
        $this->h('X-Content-Type-Options', 'nosniff');
        $this->h('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->h('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Clickjacking + XSS
        if ($this->profile === 'web') {
            $this->h('Content-Security-Policy',
                "default-src 'self'; " .
                "base-uri 'self'; " .
                "object-src 'none'; " .
                "frame-ancestors 'none'; " .
                "img-src 'self' data:; " .
                "script-src 'self'; " .
                "style-src 'self' 'unsafe-inline';"
            );
        }

        // HTTPS hardening
        if ($this->isHttps()) {
            $this->h('Strict-Transport-Security', 'max-age=15552000; includeSubDomains');
        }

        $next();
    }

    private function h(string $name, string $value): void
    {
        header($name . ': ' . $value, true);
    }

    private function isHttps(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        );
    }
}
