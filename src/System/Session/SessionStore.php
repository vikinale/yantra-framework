<?php
declare(strict_types=1);

namespace System\Session;

use System\Contracts\SessionAdapterInterface;
use System\Contracts\SessionInterface;

/**
 * SessionStore — static facade for session management.
 *
 * All static methods delegate to a shared SessionManager instance.
 * Existing call sites (`SessionStore::get()`, `SessionStore::set()`, etc.)
 * continue to work without changes. New code can inject `SessionInterface` via DI.
 */
final class SessionStore
{
    private static ?SessionManager $instance = null;

    /**
     * Get (or lazily create) the backing SessionManager instance.
     */
    public static function getInstance(): SessionManager
    {
        if (self::$instance === null) {
            self::$instance = new SessionManager();
        }
        return self::$instance;
    }

    /**
     * Inject a SessionManager (or any SessionInterface implementation).
     * Called by Application bootstrap or tests to wire the shared instance.
     */
    public static function setInstance(SessionInterface $mgr): void
    {
        if (!$mgr instanceof SessionManager) {
            throw new \InvalidArgumentException(
                'SessionStore::setInstance() currently requires a SessionManager instance.'
            );
        }
        self::$instance = $mgr;
    }

    /* -------------------- Lifecycle (delegated) -------------------- */

    /**
     * Initialize the session store with an adapter.
     * Call early in bootstrap before any output.
     */
    public static function init(?SessionAdapterInterface $adapter = null): void
    {
        self::getInstance()->init($adapter);
    }

    public static function is_init(): bool
    {
        return self::getInstance()->isInit();
    }

    public static function ensureStarted(): void
    {
        self::getInstance()->ensureStarted();
    }

    public static function setAdapter(SessionAdapterInterface $adapter): void
    {
        self::getInstance()->setAdapter($adapter);
    }

    public static function isStarted(): bool
    {
        return self::getInstance()->isStarted();
    }

    /* -------------------- Core KV API (dot-notation supported) -------------------- */

    public static function set(string $key, mixed $value): void
    {
        self::getInstance()->set($key, $value);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::getInstance()->get($key, $default);
    }

    public static function has(string $key): bool
    {
        return self::getInstance()->has($key);
    }

    public static function remove(string $key): void
    {
        self::getInstance()->remove($key);
    }

    public static function all(): array
    {
        return self::getInstance()->all();
    }

    public static function clear(): void
    {
        self::getInstance()->clear();
    }

    public static function regenerate(bool $deleteOldSession = true): void
    {
        self::getInstance()->regenerate($deleteOldSession);
    }

    public static function destroy(): void
    {
        self::getInstance()->destroy();
    }

    /* -------------------- App helpers -------------------- */

    public static function loginSuccess(int|string $user_id, string $email, array $roles, string $name): void
    {
        self::getInstance()->loginSuccess($user_id, $email, $roles, $name);
    }

    /* -------------------- Bags: auth / flash -------------------- */

    /**
     * Auth bag stored under 'auth'.
     * - auth($key, $value) => set
     * - auth($key) => pull (get & remove)
     */
    public static function auth(string $key, mixed $value = null): mixed
    {
        // We must preserve the func_num_args() behavior,
        // so we check here and call the instance method accordingly
        if (\func_num_args() >= 2) {
            return self::getInstance()->auth($key, $value);
        }
        return self::getInstance()->auth($key);
    }

    public static function setFlash(string $key, mixed $value): void
    {
        self::getInstance()->setFlash($key, $value);
    }

    public static function removeFlash(string $key): void
    {
        self::getInstance()->removeFlash($key);
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return self::getInstance()->getFlash($key, $default);
    }

    /**
     * Reset the session store entirely (useful for test isolation).
     *
     * After calling this, you must call init() again before using the session.
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->resetState();
        }
        self::$instance = null;
    }
}
