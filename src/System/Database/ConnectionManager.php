<?php
declare(strict_types=1);

namespace System\Database;

use RuntimeException;

/**
 * ConnectionManager
 *
 * Name-keyed registry of {@see Database} connections for the multi-database
 * (one organization DB + one DB per branch) topology.
 *
 *  - 'org'    : process-stable organization connection, registered via a factory
 *               at application boot. Resolved once and cached for the process.
 *  - 'branch' : request-bound branch connection, bound with set() before any
 *               branch-scoped service runs and dropped with forget() at request
 *               teardown.
 *
 * This registry intentionally does NOT fall back to the legacy global
 * {@see ConnectionResolver}; callers that want that behaviour (e.g. MasterModel)
 * decide for themselves when has() is false.
 */
final class ConnectionManager
{
    public const DEFAULT = 'org';

    /** @var array<string,Database> resolved instances */
    private static array $resolved = [];

    /** @var array<string,callable():Database> lazy factories */
    private static array $factories = [];

    /** Register a lazy factory for a named connection (last registration wins). */
    public static function register(string $name, callable $factory): void
    {
        self::$factories[$name] = $factory;
    }

    /** Bind an already-constructed Database under a name (request-scoped, e.g. 'branch'). */
    public static function set(string $name, Database $db): void
    {
        self::$resolved[$name] = $db;
    }

    /** True if a connection is resolvable under this name (resolved instance or a factory). */
    public static function has(string $name): bool
    {
        return isset(self::$resolved[$name]) || isset(self::$factories[$name]);
    }

    /**
     * Resolve a named connection. A bound/resolved instance wins over a factory.
     *
     * @throws RuntimeException when the name is unknown.
     */
    public static function get(string $name): Database
    {
        if (isset(self::$resolved[$name])) {
            return self::$resolved[$name];
        }

        if (isset(self::$factories[$name])) {
            $db = (self::$factories[$name])();
            if (!$db instanceof Database) {
                throw new RuntimeException(
                    "Connection factory for '{$name}' did not return a Database instance."
                );
            }
            self::$resolved[$name] = $db;
            return $db;
        }

        throw new RuntimeException("No database connection registered for '{$name}'.");
    }

    /**
     * Drop a resolved connection instance. Any registered factory is kept so a
     * subsequent get() rebuilds it. Used for 'branch' at end of request.
     */
    public static function forget(string $name): void
    {
        unset(self::$resolved[$name]);
    }

    /** Full reset (test isolation). */
    public static function clear(): void
    {
        self::$resolved  = [];
        self::$factories = [];
    }
}
