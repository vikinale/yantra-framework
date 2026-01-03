<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\ApiResponse;
use System\Http\Request;
use System\Http\Response;

/**
 * Fixed-window rate limiter (APCu preferred, filesystem fallback).
 *
 * Can be used:
 *  - as a router middleware via RateLimitMiddleware::handle(...)
 *  - or as an invokable instance via (new RateLimitMiddleware(...))($req,$res,$next)
 *
 * Params (router):
 *  - key: string namespace key (default "global")
 *  - limit: int requests per window (default 60)
 *  - window: int seconds (default 60)
 *  - by: "ip" | "route" | "ip+route" (default "ip+route")
 *  - message: string error message (optional)
 *  - headers: bool include X-RateLimit-* headers (default true)
 */
final class RateLimitMiddleware
{
    public function __construct(
        private int $limit = 60,
        private int $windowSeconds = 60,
        private string $key = 'global',
        private string $by = 'ip+route',
        private bool $emitHeaders = true,
        private string $message = 'Too many requests. Please try again later.',
        private bool $fileCounter = false
    ) {}

    /**
     * Router-friendly static entry.
     *
     * Signature:
     * middleware(Request $req, Response $res, callable $next, array $params): void
     */
    public static function handle(Request $req, Response $res, callable $next, array $params): void
    {
        $limit  = isset($params['limit']) ? max(1, (int)$params['limit']) : 5;
        $window = isset($params['window']) ? max(1, (int)$params['window']) : 60;
        $key    = isset($params['key']) ? trim((string)$params['key']) : 'global';
        $by     = isset($params['by']) ? trim((string)$params['by']) : 'ip+route';
        $msg    = isset($params['message']) ? (string)$params['message'] : 'Too many requests. Please try again later.';
        $hdrs   = array_key_exists('headers', $params) ? (bool)$params['headers'] : true;
        $fc = array_key_exists('fileCounter', $params) ? (bool)$params['fileCounter'] : false;

        $mw = new self(
            limit: $limit,
            windowSeconds: $window,
            key: $key,
            by: $by,
            emitHeaders: $hdrs,
            message: $msg,
            fileCounter:$fc 
        );

        $mw->__invoke($req, $res, $next);
    }

    /**
     * Invokable form (DI-friendly).
     */
    public function __invoke(Request $request, Response $response, callable $next): mixed
    {
        $now = time();

        $identifier = $this->identifier($request);
        // APCu path (fast)
        if (function_exists('apcu_inc') && function_exists('apcu_add') && ini_get('apc.enabled')) {
            $bucket = intdiv($now, $this->windowSeconds);
            $storeKeyBase = sha1($this->key . '|' . $identifier);
            $storeKey = $storeKeyBase . ':' . $bucket;
            [$count, $resetAt] = $this->increment($storeKey, $now);
        }
        elseif($this->fileCounter){
            $bucket = intdiv($now, $this->windowSeconds);
            $storeKeyBase = sha1($this->key . '|' . $identifier);
            $storeKey = $storeKeyBase . ':' . $bucket;
            [$count, $resetAt] = $this->incrementFileCounter($storeKey, $now);
        }
        else{
            [$count, $resetAt] = $this->incrementSessionCounter($this->key . '|' . $identifier, $this->windowSeconds);
        }

        if ($count > $this->limit) {
            $retryAfter = max(1, $resetAt - $now);

            // Ensure Retry-After is present even if ApiResponse is used
            $response = $response->withHeader('Retry-After', (string)$retryAfter);

            ApiResponse::tooManyRequests(
                $response,
                message: $this->message,
                retryAfter: $retryAfter,
                meta: [
                    'key'       => $this->key,
                    'limit'     => $this->limit,
                    'window'    => $this->windowSeconds,
                    'reset_at'  => $resetAt,
                    'by'        => $this->by,
                ]
            );
        }

        if ($this->emitHeaders) {
            $remaining = max(0, $this->limit - $count);
            $response = $response
                ->withHeader('X-RateLimit-Limit', (string)$this->limit)
                ->withHeader('X-RateLimit-Remaining', (string)$remaining)
                ->withHeader('X-RateLimit-Reset', (string)$resetAt);
        }

        return $next($request, $response);
    }

    private function identifier(Request $request): string
    {
        $by = strtolower($this->by);

        $ip = $this->clientIp($request) ?: 'unknown';
        $route = $this->routeKey($request);

        return match ($by) {
            'ip'       => $ip,
            'route'    => $route,
            default    => $ip . '|' . $route, // "ip+route"
        };
    }

    private function clientIp(Request $request): ?string
    {
        // Support both method names to avoid breaking changes
        $psr = null;
        if (method_exists($request, 'getPsrRequest')) {
            $psr = $request->getPsrRequest();
        } elseif (method_exists($request, 'getPsr7')) {
            $psr = $request->getPsrRequest();
        }

        if (!$psr) return null;

        $server = $psr->getServerParams();
        $ip = $server['REMOTE_ADDR'] ?? null;

        return (is_string($ip) && $ip !== '') ? $ip : null;
    }

    private function routeKey(Request $request): string
    {
        $psr = null;
        if (method_exists($request, 'getPsrRequest')) {
            $psr = $request->getPsrRequest();
        } elseif (method_exists($request, 'getPsr7')) {
            $psr = $request->getPsrRequest();
        }

        if (!$psr) return 'UNKNOWN /';

        $method = strtoupper($psr->getMethod());
        $path = $psr->getUri()->getPath();

        return $method . ' ' . $path;
    }

    /**
     * Returns [count, resetAt].
     */
    private function increment(string $storeKey, int $now): array
    {
        $resetAt = (int)(floor($now / $this->windowSeconds) * $this->windowSeconds) + $this->windowSeconds;

        if (!apcu_add($storeKey, 1, $this->windowSeconds)) {
            $count = (int)apcu_inc($storeKey, 1);
        } else {
            $count = 1;
        }
        return [$count, $resetAt];
    }

    private function incrementFileCounter(string $storeKey, int $now): array
    {
        $resetAt = (int)(floor($now / $this->windowSeconds) * $this->windowSeconds) + $this->windowSeconds;
        $dir = rtrim(BASEPATH). DIRECTORY_SEPARATOR . 'storage'. DIRECTORY_SEPARATOR .'ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        if (!is_writable($dir)) {
            throw new \RuntimeException('Directory is not writable: ' . $dir);
        }
 
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $storeKey) ?: sha1($storeKey);
        $safe = trim($safe, ' .');                 // Windows: no trailing dots/spaces
        $safe = substr($safe, 0, 120);             // keep it short
        if ($safe === '') $safe = sha1($storeKey);

        $file = $dir . DIRECTORY_SEPARATOR . $safe . '.txt';
        error_log($file);
        $count = 1;

        $fp = fopen($file, 'c+');
        if ($fp === false) {
            $err = error_get_last();
            throw new \RuntimeException('fopen failed: ' . ($err['message'] ?? 'unknown'));
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException('flock failed: ' . $file);
            }

            rewind($fp);
            $raw  = stream_get_contents($fp);
            $prev = is_string($raw) ? (int)trim($raw) : 0;

            $count = $prev + 1;

            rewind($fp);
            ftruncate($fp, 0);

            $written = fwrite($fp, (string)$count);
            if ($written === false) {
                throw new \RuntimeException('fwrite failed: ' . $file);
            }

            fflush($fp); // ensure it hits disk before unlock/close
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return [$count, $resetAt];
    }

    private function incrementSessionCounter(string $key, int $windowSeconds): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['ratelimit'])) {
            $_SESSION['ratelimit'] = [];
        }

        $now = time();

        if (
            !isset($_SESSION['ratelimit'][$key]) ||
            !is_array($_SESSION['ratelimit'][$key]) ||
            ($_SESSION['ratelimit'][$key]['reset'] ?? 0) <= $now
        ) {
            $_SESSION['ratelimit'][$key] = [
                'count' => 0,
                'reset' => $now + $windowSeconds,
            ];
        }

        $_SESSION['ratelimit'][$key]['count']++;

        return [
            $_SESSION['ratelimit'][$key]['count'],
            $_SESSION['ratelimit'][$key]['reset'],
        ];
    }

}
