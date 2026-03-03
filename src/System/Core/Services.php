<?php
declare(strict_types=1);

namespace System\Core;

use RuntimeException;

final class Services
{
    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, callable> */
    private array $factories = [];

    /**
     * Bind a service factory (singleton behavior).
     * The factory is only called once.
     */
    public function bind(string $key, callable $factory): void
    {
        $this->factories[$key] = $factory;
        unset($this->instances[$key]); // Reset instance if re-bound
    }

    /**
     * Get a service instance.
     */
    public function get(string $key): mixed
    {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        if (isset($this->factories[$key])) {
            $instance = ($this->factories[$key])($this);
            $this->instances[$key] = $instance;
            return $instance;
        }

        throw new RuntimeException("Service not found: {$key}");
    }

    /**
     * Check if a service is bound.
     */
    public function has(string $key): bool
    {
        return isset($this->instances[$key]) || isset($this->factories[$key]);
    }

    /**
     * Clear a specific service instance (force re-instantiation on next get).
     */
    public function clear(string $key): void
    {
        unset($this->instances[$key]);
    }
}
