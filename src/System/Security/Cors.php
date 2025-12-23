<?php
declare(strict_types=1);

namespace System\Security;

use System\Http\Request;
use System\Http\Response;

final class Cors
{
    /**
     * Policy keys (all optional):
     *  - origins: string[] | ['*']
     *  - methods: string[] (e.g. ['GET','POST','OPTIONS'])
     *  - headers: string[] (allowed request headers)
     *  - expose_headers: string[]
     *  - credentials: bool
     *  - max_age: int
     */
    public static function handle(Request $req, Response $res, array $policy): bool
    {
        $origin = self::getHeader('Origin');

        // If no Origin header => not a CORS request
        if ($origin === null || $origin === '') {
            return false;
        }

        $origins     = $policy['origins'] ?? [];
        $methods     = $policy['methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        $headers     = $policy['headers'] ?? [];
        $expose      = $policy['expose_headers'] ?? [];
        $credentials = (bool)($policy['credentials'] ?? false);
        $maxAge      = (int)($policy['max_age'] ?? 600);

        $allowOrigin = self::resolveAllowOrigin($origin, $origins);
        if ($allowOrigin === null) {
            // Disallowed origin: do not set any CORS headers
            return false;
        }

        self::setHeader($res, 'Access-Control-Allow-Origin', $allowOrigin);

        // Vary: Origin prevents caches mixing origins
        self::appendVary($res, 'Origin');

        if ($credentials) {
            self::setHeader($res, 'Access-Control-Allow-Credentials', 'true');
        }

        if ($expose !== []) {
            self::setHeader($res, 'Access-Control-Expose-Headers', implode(', ', self::normalizeList($expose)));
        }

        $method = strtoupper($req->getMethod());

        // Preflight request
        if ($method === 'OPTIONS') {
            self::setHeader($res, 'Access-Control-Allow-Methods', implode(', ', self::normalizeList($methods)));

            $reqHeaders = self::getHeader('Access-Control-Request-Headers');
            if ($headers !== []) {
                self::setHeader($res, 'Access-Control-Allow-Headers', implode(', ', self::normalizeList($headers)));
            } elseif ($reqHeaders) {
                // If you want "reflect" behavior (less strict), allow requested headers:
                self::setHeader($res, 'Access-Control-Allow-Headers', $reqHeaders);
            }

            self::setHeader($res, 'Access-Control-Max-Age', (string)max(0, $maxAge));

            $res->withStatus(204);
            return true;
        }

        return false;
    }

    private static function resolveAllowOrigin(string $origin, array $origins): ?string
    {
        // Allow all (only safe without credentials)
        if ($origins === ['*'] || (count($origins) === 1 && $origins[0] === '*')) {
            return '*';
        }

        foreach ($origins as $o) {
            if (!is_string($o)) continue;
            $o = trim($o);
            if ($o === '') continue;
            if (strcasecmp($o, $origin) === 0) {
                return $origin; // echo back exact origin
            }
        }

        return null;
    }

    private static function normalizeList(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            if (!is_string($i)) continue;
            $i = strtoupper(trim($i));
            if ($i !== '') $out[] = $i;
        }
        return array_values(array_unique($out));
    }

    private static function getHeader(string $name): ?string
    {
        // getallheaders (Apache/FPM)
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            if (is_array($h)) {
                foreach ($h as $k => $v) {
                    if (strcasecmp((string)$k, $name) === 0) {
                        $v = is_array($v) ? implode(',', $v) : (string)$v;
                        return trim($v);
                    }
                }
            }
        }

        // $_SERVER fallback
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return trim((string)$_SERVER[$key]);
        }

        // Some servers expose Authorization differently; not required here
        return null;
    }

    private static function setHeader(Response $res, string $name, string $value): void
    {
        if (method_exists($res, 'withHeader')) {
            $res->withHeader($name, $value);
            return;
        }
        header($name . ': ' . $value, true);
    }

    private static function appendVary(Response $res, string $value): void
    {
        // If Response has a way to append header, use it; otherwise set a safe default.
        // We keep it simple: always set Vary to include Origin.
        // (If you need merge semantics, implement Response::headerAppend in future.)
        self::setHeader($res, 'Vary', $value);
    }
}
