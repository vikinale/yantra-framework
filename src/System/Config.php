<?php
declare(strict_types=1);

namespace System;

/**
 * Lightweight configuration repository.
 *
 * Core principles:
 * - No hard dependency on an application directory (e.g., App/Config).
 * - Applications may point Yantra to their config directory via setBasePath()/setConfigDir().
 */
final class Config
{
    private static array $settings = [];
    private static ?string $basePath = null;
    private static ?string $appPath = null;
    private static string $configDir = 'config';

    public static function setBasePath(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/\\');
    }

    public static function setAppPath(string $appPath): void
    {
        self::$appPath = rtrim($appPath, '/\\');
    }

    public static function setConfigDir(string $configDir): void
    {
        self::$configDir = trim($configDir, '/\\');
    }
 
    /**
     * Load a config file and cache it under its root key.
     *
     * Example: Config::read('app') loads: {basePath}/{configDir}/app.php
     */
    public static function read(string $name): array
    {
        $file = self::$appPath . DIRECTORY_SEPARATOR . self::$configDir . DIRECTORY_SEPARATOR . $name . '.php';

        if (!is_file($file)) {
            return [];
        }

        $config = require $file;
        if (!is_array($config)) {
            throw new \RuntimeException("Config file [$name] must return an array.");
        }

        self::$settings[$name] = $config;
        return $config;
    }

    /**
     * Get a config value by dot-notation.
     * Examples:
     *   Config::get('security.token_secret');
     *   Config::get('redis.host');
     */
    public static function get(string $key, $default = null)
    {
        $parts = explode('.', $key);
        $root = array_shift($parts);

        if ($root === null || $root === '') {
            return $default;
        }

        // Lazy load config file if not loaded yet
        if (!array_key_exists($root, self::$settings)) {
            self::read($root);
        }

        if (!isset(self::$settings[$root])) {
            return $default;
        }

        $value = self::$settings[$root];

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * Set config dynamically (optional).
     */
    public static function set(string $key, $value): void
    {
        $parts = explode('.', $key);
        $root = array_shift($parts);

        if ($root === null || $root === '') {
            return;
        }

        if (!isset(self::$settings[$root])) {
            self::$settings[$root] = [];
        }

        $ref =& self::$settings[$root];
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref =& $ref[$part];
        }

        $ref = $value;
    }
}
