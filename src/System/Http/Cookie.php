<?php
declare(strict_types=1);

namespace System\Http;

final class Cookie
{
    private string $path;
    private string $domain;
    private bool $secure;
    private bool $httpOnly;
    private string $sameSite;

    public function __construct(
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ) {
        $this->path = $path;
        $this->domain = $domain;
        $this->secure = $secure;
        $this->httpOnly = $httpOnly;
        $this->sameSite = $sameSite;
    }

    /**
     * Set cookie with TTL seconds from now.
     */
    public function set(string $name, mixed $value, int $ttlSeconds = 3600): bool
    {
        $expires = time() + $ttlSeconds;

        if (!headers_sent()) {
            return setcookie($name, (string)$value, [
                'expires'  => $expires,
                'path'     => $this->path,
                'domain'   => $this->domain,
                'secure'   => $this->secure,
                'httponly' => $this->httpOnly,
                'samesite' => $this->sameSite,
            ]);
        }

        // legacy fallback
        return setcookie($name, (string)$value, $expires, $this->path, $this->domain, $this->secure, $this->httpOnly);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $_COOKIE[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $_COOKIE);
    }

    public function delete(string $name): bool
    {
        if (!$this->has($name)) {
            return false;
        }

        if (!headers_sent()) {
            return setcookie($name, '', [
                'expires'  => time() - 3600,
                'path'     => $this->path,
                'domain'   => $this->domain,
                'secure'   => $this->secure,
                'httponly' => $this->httpOnly,
                'samesite' => $this->sameSite,
            ]);
        }

        return setcookie($name, '', time() - 3600, $this->path, $this->domain, $this->secure, $this->httpOnly);
    }

    public function setParams(string $path, string $domain, bool $secure, bool $httpOnly, string $sameSite = 'Lax'): void
    {
        $this->path = $path;
        $this->domain = $domain;
        $this->secure = $secure;
        $this->httpOnly = $httpOnly;
        $this->sameSite = $sameSite;
    }
}
