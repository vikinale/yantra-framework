<?php
namespace System\Utilities;

/**
 * SessionStore
 *
 * Simple wrapper around session handling with a pluggable adapter.
 * DefaultAdapter uses native PHP sessions.
 *
 * Usage:
 *   SessionStore::init(); // call early in bootstrap
 *   SessionStore::set('user_id', 123);
 *   $id = SessionStore::get('user_id');
 */
class SessionStore
{
    protected static ?SessionAdapterInterface $adapter = null;
    protected static bool $started = false;

    public static function init(SessionAdapterInterface $adapter = null): void
    {
        if ($adapter !== null) {
            self::$adapter = $adapter;
        } elseif (self::$adapter === null) {
            self::$adapter = new NativeSessionAdapter();
        }

        if (!self::$started) {
            self::$adapter->start();
            self::$started = true;
        }
    }

    public static function set(string $key, $value): void
    {
        self::ensureStarted();
        self::$adapter->set($key, $value);
    }

    public static function get(string $key, $default = null)
    {
        self::ensureStarted();
        return self::$adapter->get($key, $default);
    }

    public static function has(string $key): bool
    {
        self::ensureStarted();
        return self::$adapter->has($key);
    }

    public static function remove(string $key): void
    {
        self::ensureStarted();
        self::$adapter->remove($key);
    }

    public static function regenerate(): void
    {
        self::ensureStarted();
        self::$adapter->regenerate();
    }

    public static function destroy(): void
    {
        self::ensureStarted();
        self::$adapter->destroy();
    }

    public static function all(): array
    {
        self::ensureStarted();
        return self::$adapter->all();
    }

    public static function setAdapter(SessionAdapterInterface $adapter): void
    {
        self::$adapter = $adapter;
        if (self::$started) self::$adapter->start();
    }

    protected static function ensureStarted(): void
    {
        if (!self::$started) {
            self::init();
        }
    }
}

/* -------------------------
 * Adapter interface & default adapter
 * ------------------------- */

interface SessionAdapterInterface
{
    public function start(): void;
    public function get(string $key, $default = null);
    public function set(string $key, $value): void;
    public function has(string $key): bool;
    public function remove(string $key): void;
    public function regenerate(): void;
    public function destroy(): void;
    public function all(): array;
}

/**
 * NativeSessionAdapter - uses PHP sessions
 */
class NativeSessionAdapter implements SessionAdapterInterface
{
    public function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Recommended settings; tweak as needed in production
            if (!headers_sent()) {
                session_set_cookie_params([
                    'lifetime' => 0,
                    'path' => '/',
                    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            session_start();
        }
    }

    public function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, $value): void
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

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_NONE) {
            session_destroy();
        }
    }

    public function all(): array
    {
        return $_SESSION;
    }
}

/**
 * RedisSessionAdapter (example) - requires ext-redis or predis client
 *
 * NOTE: this adapter stores session data in Redis and still uses PHP session
 * to propagate an id (you can also implement fully custom session handler).
 * This is a minimal example - in production, use session_set_save_handler() or
 * a battle-tested library.
 */
class RedisSessionAdapter implements SessionAdapterInterface
{
    protected $redis;
    protected string $prefix;
    protected int $ttl;

    public function __construct($redisClient, string $prefix = 'sess:', int $ttl = 3600)
    {
        $this->redis = $redisClient;
        $this->prefix = $prefix;
        $this->ttl = $ttl;
    }

    public function start(): void
    {
        // Use PHP session but load + save to Redis on start/destroy would be better.
        // For a full implementation use session_set_save_handler().
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
        // optional: persist to redis
        $sid = session_id();
        try {
            $this->redis->setex($this->prefix . $sid, $this->ttl, serialize($_SESSION));
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $sid = session_id();
        try {
            $this->redis->del($this->prefix . $sid);
        } catch (\Throwable $e) { }
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_NONE) session_destroy();
    }

    public function all(): array
    {
        return $_SESSION;
    }
}
