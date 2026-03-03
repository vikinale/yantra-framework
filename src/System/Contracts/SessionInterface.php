<?php
declare(strict_types=1);

namespace System\Contracts;

/**
 * SessionInterface — contract for session management.
 *
 * Abstracts session operations so consumers can depend on an interface
 * rather than the static SessionStore facade. Supports dot-notation keys,
 * flash data, and auth bags.
 */
interface SessionInterface
{
    /**
     * Get a session value by key (supports dot-notation).
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a session value by key (supports dot-notation).
     */
    public function set(string $key, mixed $value): void;

    /**
     * Check whether a session key exists.
     */
    public function has(string $key): bool;

    /**
     * Remove a session key.
     */
    public function remove(string $key): void;

    /**
     * Return all session data.
     */
    public function all(): array;

    /**
     * Clear all session data.
     */
    public function clear(): void;

    /**
     * Regenerate the session ID.
     */
    public function regenerate(bool $deleteOldSession = true): void;

    /**
     * Destroy the session entirely.
     */
    public function destroy(): void;

    /**
     * Set a flash value (available for one subsequent request).
     */
    public function setFlash(string $key, mixed $value): void;

    /**
     * Get (and consume) a flash value.
     */
    public function getFlash(string $key, mixed $default = null): mixed;

    /**
     * Remove a flash value without consuming it.
     */
    public function removeFlash(string $key): void;
}
