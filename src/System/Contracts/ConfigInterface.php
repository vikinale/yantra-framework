<?php
declare(strict_types=1);

namespace System\Contracts;

/**
 * ConfigInterface — contract for configuration repositories.
 *
 * Allows swapping the config backend (file-based, array, remote, etc.)
 * and enables constructor injection + mock-friendly testing.
 */
interface ConfigInterface
{
    /**
     * Get a config value by dot-notation key.
     *
     * @param string $key     e.g. "app.name", "db.host"
     * @param mixed  $default returned when key not found
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a config value dynamically.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Check whether a config key exists (even if its value is null).
     */
    public function has(string $key): bool;

    /**
     * Deep-merge defaults into the config under a given key.
     * Existing values take precedence over defaults.
     */
    public function merge(string $key, array $defaults): void;

    /**
     * Load (or reload) a config file by name and return its array.
     *
     * @return array the loaded config data
     */
    public function read(string $name): array;

    /**
     * Return all loaded config data.
     */
    public function all(): array;

    /**
     * Get an environment variable with type-casting.
     */
    public function env(string $key, mixed $default = null): mixed;

    /**
     * Reset all loaded configuration (useful for test isolation).
     */
    public function reset(): void;
}
