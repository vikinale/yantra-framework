<?php
declare(strict_types=1);

namespace System;

use System\Contracts\HooksInterface;
use System\Hooks\HooksRepository;

/**
 * Hooks — static facade for actions & filters.
 *
 * All static methods delegate to a shared HooksRepository instance.
 * Existing call sites (`Hooks::add_action()`, `Hooks::do_action()`, etc.)
 * continue to work without changes. New code can inject `HooksInterface` via DI.
 *
 * Supports priorities, named registrations, and accepted args.
 */
final class Hooks
{
    private static ?HooksRepository $instance = null;

    /**
     * Get (or lazily create) the backing HooksRepository instance.
     */
    public static function getInstance(): HooksRepository
    {
        if (self::$instance === null) {
            self::$instance = new HooksRepository();
        }
        return self::$instance;
    }

    /**
     * Inject a HooksRepository (or any HooksInterface implementation).
     * Called by Application bootstrap or tests to wire the shared instance.
     */
    public static function setInstance(HooksInterface $repo): void
    {
        if (!$repo instanceof HooksRepository) {
            throw new \InvalidArgumentException(
                'Hooks::setInstance() currently requires a HooksRepository instance.'
            );
        }
        self::$instance = $repo;
    }

    /* -------------------- Delegated static API -------------------- */

    /**
     * Register an action.
     *
     * @param string        $hook
     * @param callable      $callback
     * @param int           $priority
     * @param string|null   $name          optional unique name for removal/replacement
     * @param int           $accepted_args how many args from do_action to pass to callback
     */
    public static function add_action(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1): void
    {
        self::getInstance()->addAction($hook, $callback, $priority, $name, $accepted_args);
    }

    /**
     * Fire an action: call all callbacks for the hook with provided args.
     */
    public static function do_action(string $hook, ...$args): void
    {
        self::getInstance()->doAction($hook, ...$args);
    }

    /**
     * Register a filter.
     */
    public static function add_filter(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1): void
    {
        self::getInstance()->addFilter($hook, $callback, $priority, $name, $accepted_args);
    }

    /**
     * Apply filters to a value (pipe $value through callbacks).
     *
     * @return mixed filtered value
     */
    public static function apply_filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        return self::getInstance()->applyFilter($hook, $value, ...$args);
    }

    /**
     * Remove a named action added with a name.
     */
    public static function remove_action_by_name(string $hook, string $name): bool
    {
        return self::getInstance()->removeActionByName($hook, $name);
    }

    /**
     * Remove a named filter added with a name.
     */
    public static function remove_filter_by_name(string $hook, string $name): bool
    {
        return self::getInstance()->removeFilterByName($hook, $name);
    }

    /**
     * Check whether any action exists for hook.
     */
    public static function has_action(string $hook): bool
    {
        return self::getInstance()->hasAction($hook);
    }

    /**
     * Check whether any filter exists for hook.
     */
    public static function has_filter(string $hook): bool
    {
        return self::getInstance()->hasFilter($hook);
    }

    /**
     * Reset all hooks and clear the backing instance.
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
