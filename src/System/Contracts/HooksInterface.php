<?php
declare(strict_types=1);

namespace System\Contracts;

/**
 * HooksInterface — contract for the action/filter hooks system.
 *
 * Modelled after WordPress-style hooks with priorities and named
 * registrations. Allows mock hooks in tests and alternative
 * event-dispatcher backends.
 */
interface HooksInterface
{
    /**
     * Register an action callback for a hook.
     */
    public function addAction(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $acceptedArgs = 1): void;

    /**
     * Fire all callbacks registered for an action hook.
     */
    public function doAction(string $hook, mixed ...$args): void;

    /**
     * Register a filter callback for a hook.
     */
    public function addFilter(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $acceptedArgs = 1): void;

    /**
     * Pipe a value through all callbacks registered for a filter hook.
     *
     * @return mixed the filtered value
     */
    public function applyFilter(string $hook, mixed $value, mixed ...$args): mixed;

    /**
     * Remove a named action by its registration name.
     */
    public function removeActionByName(string $hook, string $name): bool;

    /**
     * Remove a named filter by its registration name.
     */
    public function removeFilterByName(string $hook, string $name): bool;

    /**
     * Check whether any action is registered for a hook.
     */
    public function hasAction(string $hook): bool;

    /**
     * Check whether any filter is registered for a hook.
     */
    public function hasFilter(string $hook): bool;

    /**
     * Reset all hooks (useful for test isolation).
     */
    public function reset(): void;
}
