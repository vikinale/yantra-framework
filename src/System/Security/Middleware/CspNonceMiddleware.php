<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;
use System\Security\Csp\CspNonce;

final class CspNonceMiddleware
{
    public function __construct(private string $profile = 'web')
    {
    }

    public function __invoke(Request $req, Response $res, callable $next, array $params): void
    {
        $nonce = CspNonce::get();

        // Base directives (safe defaults)
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "img-src 'self' data:",
            "script-src 'self' 'nonce-{$nonce}'",
        ];

        if ($this->profile === 'admin') {
            // Admin: lock down network calls and embeddings more aggressively
            $directives[] = "connect-src 'self'";
            $directives[] = "frame-src 'none'";

            // Styles: keep inline for compatibility unless you are ready to refactor admin CSS.
            $directives[] = "style-src 'self' 'unsafe-inline'";

            // Optional: reduce information leaks
            $directives[] = "font-src 'self'";
            $directives[] = "media-src 'self'";
        } else {
            // Web: usually a little more flexible (adjust as needed)
            $directives[] = "connect-src 'self'";
            $directives[] = "style-src 'self' 'unsafe-inline'";
            $directives[] = "font-src 'self' data:";
        }

        // Good extra hardening (safe on most sites)
        $directives[] = "upgrade-insecure-requests";

        $csp = implode('; ', $directives);
        header('Content-Security-Policy: ' . $csp, true);

        $next();
    }
}
/*
Admin group overrides CSP

Add sec.csp:admin inside /admin group middleware list. It will run later in the chain and override the CSP header (last header wins).

$r->group([
    'prefix' => '/admin',
    'middleware' => [
        'sec.csp:admin',
        'sec.auth',
        'sec.admin',
        'sec.csrf',
    ],
], function($r) {
    $r->get('/dashboard', [\App\Controllers\AdminController::class, 'index']);
});
*/