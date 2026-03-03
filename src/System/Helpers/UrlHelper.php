<?php

namespace System\Helpers;

/**
 * Class UrlHelper
 *
 * Utilities for building and manipulating URLs.
 */
class UrlHelper
{
    /**
     * Return the base URL for the application.
     *
     * If you have an application config value for base URL, prefer to read from there
     * before falling back to server detection.
     *
     * @param string $path Optional path to append.
     * @return string
     */
    public static function baseUrl(string $path = ''): string
    {
        $scheme = self::isHttps() ? 'https' : 'http';
        $host = self::serverHost();
        $port = self::serverPort();

        $portPart = '';
        if ($port !== null && !in_array($port, [80, 443], true)) {
            $portPart = ':' . $port;
        }

        $base = $scheme . '://' . $host . $portPart;

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Helper to generate an asset URL (CSS, JS, images).
     *
     * Example: UrlHelper::asset('css/app.css') => https://example.com/assets/css/app.css
     *
     * @param string $path
     * @param string|null $assetsPrefix Optional assets prefix; defaults to 'assets'
     * @return string
     */
    public static function asset(string $path, ?string $assetsPrefix = 'assets'): string
    {
        $prefix = $assetsPrefix !== null ? trim($assetsPrefix, '/') : '';
        $joined = $prefix === '' ? $path : $prefix . '/' . ltrim($path, '/');
        return self::baseUrl($joined);
    }

    /**
     * Return the full current URL (including query string) where possible.
     * Falls back to empty string in non-HTTP contexts.
     *
     * @return string
     */
    public static function current(): string
    {
        if (php_sapi_name() === 'cli' || empty($_SERVER)) {
            return '';
        }

        $scheme = self::isHttps() ? 'https' : 'http';
        $host = self::serverHost();
        $port = self::serverPort();
        $portPart = ($port !== null && !in_array($port, [80, 443], true)) ? ':' . $port : '';

        $uri = $_SERVER['REQUEST_URI'] ?? ($_SERVER['PHP_SELF'] ?? '/');

        return $scheme . '://' . $host . $portPart . $uri;
    }

    /**
     * Merge or replace query parameters into a URL.
     *
     * @param string $url
     * @param array $params
     * @param bool $replace If true, keys in $params replace existing keys; otherwise they append/override
     * @return string
     */
    public static function withQuery(string $url, array $params = [], bool $replace = true): string
    {
        $parts = parse_url($url);

        $existing = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $existing);
        }

        $merged = $replace ? array_merge($existing, $params) : self::arrayMergeRecursive($existing, $params);

        $parts['query'] = $merged ? http_build_query($merged) : null;

        return self::buildUrl($parts);
    }

    /**
     * Remove specific query keys from a URL.
     *
     * @param string $url
     * @param array|string $keys
     * @return string
     */
    public static function removeQuery(string $url, $keys): string
    {
        $keys = is_array($keys) ? $keys : [$keys];

        $parts = parse_url($url);
        $existing = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $existing);
        }

        foreach ($keys as $k) {
            if (array_key_exists($k, $existing)) {
                unset($existing[$k]);
            }
        }

        $parts['query'] = $existing ? http_build_query($existing) : null;

        return self::buildUrl($parts);
    }

    /**
     * Return true if URL is external relative to the current host.
     *
     * @param string $url
     * @param bool $allowSubdomains If true, subdomains of current host are treated as internal.
     * @return bool
     */
    public static function isExternal(string $url, bool $allowSubdomains = true): bool
    {
        $urlHost = parse_url($url, PHP_URL_HOST);
        if ($urlHost === null) {
            return false; // relative URL
        }

        $currentHost = self::serverHost();
        if ($allowSubdomains) {
            return !self::hostMatchesOrSubdomain($urlHost, $currentHost);
        }

        return strcasecmp($urlHost, $currentHost) !== 0;
    }

    /**
     * Normalize a URL (remove duplicate slashes, trailing spaces).
     *
     * @param string $url
     * @return string
     */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        // Replace multiple slashes after the scheme: "http://example.com//foo" -> keep the double slash after http:
        return preg_replace('#(?<!:)//+#', '/', $url) ?: $url;
    }

    /**
     * Join multiple path segments into a single path.
     *
     * @param string ...$segments
     * @return string
     */
    public static function joinPath(string ...$segments): string
    {
        $trimmed = array_map(fn($p) => trim($p, '/'), $segments);
        $joined = implode('/', array_filter($trimmed, fn($p) => $p !== ''));
        return $joined === '' ? '' : $joined;
    }

    /**
     * Build a URL back from parse_url parts.
     *
     * @param array $parts
     * @return string
     */
    protected static function buildUrl(array $parts): string
    {
        $scheme   = $parts['scheme'] ?? null;
        $host     = $parts['host'] ?? null;
        $port     = $parts['port'] ?? null;
        $user     = $parts['user'] ?? null;
        $pass     = $parts['pass'] ?? null;
        $path     = $parts['path'] ?? '/';
        $query    = $parts['query'] ?? null;
        $fragment = $parts['fragment'] ?? null;

        $url = '';

        if ($scheme !== null) {
            $url .= $scheme . '://';
        }

        if ($user !== null) {
            $url .= $user;
            if ($pass !== null) {
                $url .= ':' . $pass;
            }
            $url .= '@';
        }

        if ($host !== null) {
            $url .= $host;
        }

        if ($port !== null) {
            $url .= ':' . $port;
        }

        $url .= $path;

        if ($query !== null && $query !== '') {
            $url .= '?' . (is_array($query) ? http_build_query($query) : $query);
        }

        if ($fragment !== null) {
            $url .= '#' . $fragment;
        }

        return $url;
    }

    /**
     * Determine if current request is over HTTPS.
     *
     * @return bool
     */
    protected static function isHttps(): bool
    {
        if (php_sapi_name() === 'cli' || empty($_SERVER)) {
            return false;
        }

        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])) {
            return strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on';
        }

        return (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    }

    /**
     * Return the host name for the current request or 'localhost' fallback.
     *
     * @return string
     */
    protected static function serverHost(): string
    {
        if (!empty($_SERVER['HTTP_HOST'])) {
            return preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']);
        }

        if (!empty($_SERVER['SERVER_NAME'])) {
            return $_SERVER['SERVER_NAME'];
        }

        return 'localhost';
    }

    /**
     * Return the server port or null when unknown.
     *
     * @return int|null
     */
    protected static function serverPort(): ?int
    {
        if (!empty($_SERVER['SERVER_PORT'])) {
            return (int) $_SERVER['SERVER_PORT'];
        }

        return null;
    }

    /**
     * Merge arrays recursively but append non-associative items.
     *
     * @param array $a
     * @param array $b
     * @return array
     */
    protected static function arrayMergeRecursive(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (is_array($v) && isset($a[$k]) && is_array($a[$k])) {
                $a[$k] = self::arrayMergeRecursive($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }
        return $a;
    }

    /**
     * Check if host matches or is a subdomain of base host.
     *
     * @param string $host
     * @param string $base
     * @return bool
     */
    protected static function hostMatchesOrSubdomain(string $host, string $base): bool
    {
        $host = strtolower($host);
        $base = strtolower($base);

        if ($host === $base) {
            return true;
        }

        return substr($host, -strlen('.' . $base)) === '.' . $base;
    }
}
