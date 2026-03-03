<?php
declare(strict_types=1);

namespace System;

use System\Config\ConfigRepository;
use System\Contracts\ConfigInterface;

/**
 * Config — static facade for the configuration repository.
 *
 * All static methods delegate to a shared ConfigRepository instance.
 * Existing call sites (`Config::get()`, `Config::set()`, etc.) continue
 * to work without changes. New code can inject `ConfigInterface` via DI.
 *
 * Core principles:
 * - No hard dependency on an application directory (e.g., App/Config).
 * - Backward-compatible: 84+ call sites require zero changes.
 */
final class Config
{
    private static ?ConfigRepository $instance = null;

    /**
     * Get (or lazily create) the backing ConfigRepository instance.
     */
    public static function getInstance(): ConfigRepository
    {
        if (self::$instance === null) {
            self::$instance = new ConfigRepository();
        }
        return self::$instance;
    }

    /**
     * Inject a ConfigRepository (or any ConfigInterface implementation).
     * Called by Application bootstrap to wire the shared instance.
     */
    public static function setInstance(ConfigInterface $repo): void
    {
        // We accept ConfigInterface but store as ConfigRepository
        // since the facade exposes ConfigRepository-specific methods (setAppPath, etc.)
        if (!$repo instanceof ConfigRepository) {
            throw new \InvalidArgumentException(
                'Config::setInstance() currently requires a ConfigRepository instance.'
            );
        }
        self::$instance = $repo;
    }

    /* -------------------- Delegated static API -------------------- */

    public static function setAppPath(string $appPath): void
    {
        self::getInstance()->setAppPath($appPath);
    }

    public static function setConfigDir(string $configDir): void
    {
        self::getInstance()->setConfigDir($configDir);
    }

    /**
     * Load a config file and cache it under its root key.
     *
     * Example: Config::read('app') loads: {basePath}/{configDir}/app.php
     */
    public static function read(string $name): array
    {
        return self::getInstance()->read($name);
    }

    /**
     * Get a config value by dot-notation.
     *
     * Examples:
     *   Config::get('security.token_secret');
     *   Config::get('redis.host');
     */
    public static function get(string $key, $default = null): mixed
    {
        return self::getInstance()->get($key, $default);
    }

    /**
     * Set config dynamically.
     */
    public static function set(string $key, $value): void
    {
        self::getInstance()->set($key, $value);
    }

    /**
     * Merge config defaults (deep merge).
     *
     * @param string $key      e.g. "app", "database.connections"
     * @param array  $defaults The default configuration to merge in.
     */
    public static function merge(string $key, array $defaults): void
    {
        self::getInstance()->merge($key, $defaults);
    }

    /**
     * Get environment variable with type casting and default.
     */
    public static function env(string $key, mixed $default = null): mixed
    {
        return self::getInstance()->env($key, $default);
    }

    /**
     * Reset all loaded config and clear the backing instance.
     * Essential for test isolation.
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->reset();
        }
        self::$instance = null;
    }
}
