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
    /**
     * Handle CORS headers on the response.
     *
     * Returns the modified Response on preflight (OPTIONS) or simple CORS requests,
     * or false if no CORS handling was needed (no Origin header or disallowed origin).
     *
     * @return Response|false
     */
    public static function handle(Request $req, Response $res, array $policy): Response|false
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

        $res = $res->withHeader('Access-Control-Allow-Origin', $allowOrigin);
        $res = $res->withHeader('Vary', 'Origin');

        if ($credentials) {
            $res = $res->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($expose !== []) {
            $res = $res->withHeader('Access-Control-Expose-Headers', implode(', ', self::normalizeList($expose)));
        }

        $method = strtoupper($req->getMethod());

        // Preflight request
        if ($method === 'OPTIONS') {
            $res = $res->withHeader('Access-Control-Allow-Methods', implode(', ', self::normalizeList($methods)));

            $reqHeaders = self::getHeader('Access-Control-Request-Headers');
            if ($headers !== []) {
                $res = $res->withHeader('Access-Control-Allow-Headers', implode(', ', self::normalizeList($headers)));
            } elseif ($reqHeaders) {
                $res = $res->withHeader('Access-Control-Allow-Headers', $reqHeaders);
            }

            $res = $res->withHeader('Access-Control-Max-Age', (string)max(0, $maxAge));
            $res = $res->withStatus(204);

            return $res;
        }

        return $res;
    }

    private static function resolveAllowOrigin(string $origin, array $origins): ?string
    {
        // Allow all (unsafe if credentials=true, so we echo back origin)
        if ($origins === ['*'] || (count($origins) === 1 && $origins[0] === '*')) {
            return $origin;
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

        return null;
    }
}
