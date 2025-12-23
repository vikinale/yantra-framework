<?php
declare(strict_types=1);

namespace System\Utilities;

final class RedisNativeSessionAdapter implements SessionAdapterInterface
{
    private RedisSessionHandler $handler;
    private array $cookie;

    /**
     * @param RedisSessionHandler $handler
     * @param array $cookieOptions Keys: lifetime,path,domain,secure,httponly,samesite
     */
    public function __construct(RedisSessionHandler $handler, array $cookieOptions = [])
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        $this->handler = $handler;
        $this->cookie = $cookieOptions + [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        if (!headers_sent()) {
            session_set_cookie_params($this->cookie);
        }

        // Register custom Redis handler (true backend)
        session_set_save_handler($this->handler, true);

        session_start();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function all(): array
    {
        return $_SESSION ?? [];
    }

    public function clear(): void
    {
        $_SESSION = [];
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOldSession);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            // best-effort cookie expire
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                $samesite = $params['samesite'] ?? ($this->cookie['samesite'] ?? 'Lax');

                if (!headers_sent()) {
                    setcookie(session_name(), '', [
                        'expires'  => time() - 42000,
                        'path'     => $params['path'] ?? '/',
                        'domain'   => $params['domain'] ?? '',
                        'secure'   => $params['secure'] ?? false,
                        'httponly' => $params['httponly'] ?? true,
                        'samesite' => $samesite,
                    ]);
                } else {
                    setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
                }
            }

            session_destroy();
        }
    }
}
