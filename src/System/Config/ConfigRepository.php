<?php
declare(strict_types=1);

namespace System\Config;

use System\Contracts\ConfigInterface;

/**
 * ConfigRepository — instance-based configuration repository.
 *
 * Holds all loaded config data as instance state. The static `Config`
 * facade delegates to a shared ConfigRepository instance, so existing
 * `Config::get()` calls continue to work without changes.
 */
final class ConfigRepository implements ConfigInterface
{
    private array $settings = [];
    private ?string $appPath;
    private string $configDir;

    public function __construct(?string $appPath = null, string $configDir = 'Config')
    {
        $this->appPath = $appPath !== null ? rtrim($appPath, '/\\') : null;
        $this->configDir = trim($configDir, '/\\');
    }

    /* -------------------- Path setters -------------------- */

    public function setAppPath(string $appPath): void
    {
        $this->appPath = rtrim($appPath, '/\\');
    }

    public function getAppPath(): ?string
    {
        return $this->appPath;
    }

    public function setConfigDir(string $configDir): void
    {
        $this->configDir = trim($configDir, '/\\');
    }

    public function getConfigDir(): string
    {
        return $this->configDir;
    }

    /* -------------------- ConfigInterface -------------------- */

    /**
     * Load a config file and cache it under its root key.
     *
     * Example: read('app') loads: {appPath}/{configDir}/app.php
     */
    public function read(string $name): array
    {
        $file = $this->appPath . DIRECTORY_SEPARATOR . $this->configDir . DIRECTORY_SEPARATOR . $name . '.php';

        if (!is_file($file)) {
            $this->settings[$name] = [];
            return [];
        }

        $config = require $file;
        if (!is_array($config)) {
            throw new \RuntimeException("Config file [$name] must return an array.");
        }

        $this->settings[$name] = $config;
        return $config;
    }

    /**
     * Get a config value by dot-notation.
     *
     * Examples:
     *   get('security.token_secret')
     *   get('redis.host')
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $root = array_shift($parts);

        if ($root === null || $root === '') {
            return $default;
        }

        // Lazy load config file if not loaded yet
        if (!array_key_exists($root, $this->settings)) {
            $this->read($root);
        }
        if (!array_key_exists($root, $this->settings)) {
            return $default;
        }

        $value = $this->settings[$root];

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * Set config dynamically.
     */
    public function set(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $root = array_shift($parts);

        if ($root === null || $root === '') {
            return;
        }

        if (!isset($this->settings[$root])) {
            $this->settings[$root] = [];
        }

        $ref =& $this->settings[$root];
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref =& $ref[$part];
        }

        $ref = $value;
    }

    /**
     * Check whether a config key exists (even if null).
     */
    public function has(string $key): bool
    {
        $parts = explode('.', $key);
        $root = array_shift($parts);

        if ($root === null || $root === '') {
            return false;
        }

        if (!array_key_exists($root, $this->settings)) {
            $this->read($root);
        }
        if (!array_key_exists($root, $this->settings)) {
            return false;
        }

        $value = $this->settings[$root];

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return false;
            }
            $value = $value[$part];
        }

        return true;
    }

    /**
     * Merge config defaults (deep merge).
     * Existing values take precedence over defaults.
     */
    public function merge(string $key, array $defaults): void
    {
        $current = $this->get($key, []);
        if (!is_array($current)) {
            $current = [];
        }

        $merged = array_replace_recursive($defaults, $current);
        $this->set($key, $merged);
    }

    /**
     * Return all loaded config data.
     */
    public function all(): array
    {
        return $this->settings;
    }

    /**
     * Get environment variable with type casting and default.
     */
    public function env(string $key, mixed $default = null): mixed
    {
        $val = $_ENV[$key] ?? getenv($key);

        if ($val === false) {
            return $default;
        }

        return match (strtolower((string) $val)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)'   => null,
            default            => $val,
        };
    }

    /**
     * Reset all loaded config (useful for test isolation).
     */
    public function reset(): void
    {
        $this->settings = [];
    }
}
