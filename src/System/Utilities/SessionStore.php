<?php
declare(strict_types=1);

namespace System\Utilities;

final class SessionStore
{
    private static ?SessionAdapterInterface $adapter = null;
    private static bool $started = false;

    /**
     * Initialize the session store with an adapter.
     * Call early in bootstrap before any output.
     */
    public static function init(?SessionAdapterInterface $adapter = null): void
    {
        if ($adapter !== null) {
            // do not allow changing adapter after session started
            if (self::$started) {
                throw new \RuntimeException('Cannot set session adapter after session has started.');
            }
            self::$adapter = $adapter;
        }

        if (self::$adapter === null) {
            self::$adapter = new NativeSessionAdapter(); // defaults
        }

        if (!self::$started) {
            self::$adapter->start();
            self::$started = true;
        }
    }

    public static function is_init(): bool
    {
        return self::$adapter !== null;
    }

    public static function set(string $key, mixed $value): void
    {
        self::ensureStarted();
        self::$adapter->set($key, $value);
    }

    public static function get(string $key, mixed $default = null): mixed
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

    public static function all(): array
    {
        self::ensureStarted();
        return self::$adapter->all();
    }

    public static function clear(): void
    {
        self::ensureStarted();
        self::$adapter->clear();
    }

    public static function regenerate(bool $deleteOldSession = true): void
    {
        self::ensureStarted();
        self::$adapter->regenerate($deleteOldSession);
    }

    public static function destroy(): void
    {
        self::ensureStarted();
        self::$adapter->destroy();
    }

    /**
     * Set adapter BEFORE the session starts.
     */
    public static function setAdapter(SessionAdapterInterface $adapter): void
    {
        if (self::$started) {
            throw new \RuntimeException('Cannot change session adapter after session has started.');
        }
        self::$adapter = $adapter;
    }

    /**
     * Optional helper for debugging/testing.
     */
    public static function isStarted(): bool
    {
        return self::$started;
    }

    private static function ensureStarted(): void
    {
        if (!self::$started) {
            self::init(null);
        }
    }
}
