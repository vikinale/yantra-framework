<?php
declare(strict_types=1);

namespace System\Security\Headers;

use System\Http\Response;

final class SecurityHeaders
{
    /**
     * Options:
     *  - nosniff: bool (default true)
     *  - frame: 'SAMEORIGIN'|'DENY'|'' (default SAMEORIGIN)
     *  - referrer: string (default 'strict-origin-when-cross-origin')
     *  - permissions: string (default minimal)
     *  - hsts: bool (default false; only enable when HTTPS)
     *  - hsts_max_age: int (default 15552000 = 180 days)
     *  - hsts_include_subdomains: bool (default true)
     *  - hsts_preload: bool (default false)
     *  - csp: string|null (default null; if set, adds Content-Security-Policy)
     */
    public static function apply(Response $res, array $opts = []): void
    {
        $nosniff   = (bool)($opts['nosniff'] ?? true);
        $frame     = (string)($opts['frame'] ?? 'SAMEORIGIN');
        $referrer  = (string)($opts['referrer'] ?? 'strict-origin-when-cross-origin');
        $perm      = (string)($opts['permissions'] ?? 'geolocation=(), microphone=(), camera=()');
        $hsts      = (bool)($opts['hsts'] ?? false);

        if ($nosniff) {
            self::setHeader($res, 'X-Content-Type-Options', 'nosniff');
        }

        if ($frame !== '') {
            self::setHeader($res, 'X-Frame-Options', $frame);
        }

        if ($referrer !== '') {
            self::setHeader($res, 'Referrer-Policy', $referrer);
        }

        if ($perm !== '') {
            self::setHeader($res, 'Permissions-Policy', $perm);
        }

        // Optional CSP
        $csp = $opts['csp'] ?? null;
        if (is_string($csp) && trim($csp) !== '') {
            self::setHeader($res, 'Content-Security-Policy', trim($csp));
        }

        // HSTS only if explicitly enabled
        if ($hsts) {
            $maxAge = (int)($opts['hsts_max_age'] ?? 15552000);
            $incSub = (bool)($opts['hsts_include_subdomains'] ?? true);
            $preload = (bool)($opts['hsts_preload'] ?? false);

            $v = 'max-age=' . max(0, $maxAge);
            if ($incSub) $v .= '; includeSubDomains';
            if ($preload) $v .= '; preload';

            self::setHeader($res, 'Strict-Transport-Security', $v);
        }
    }

    private static function setHeader(Response $res, string $name, string $value): void
    {
        $res->withHeader($name, $value);
    }
}
