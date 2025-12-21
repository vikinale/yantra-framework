<?php
declare(strict_types=1);

namespace System;

/**
 * Hooks - actions & filters with support for priorities, named registrations and accepted args.
 *
 * - add_action(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1)
 * - do_action(string $hook, ...$args)
 *
 * - add_filter(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1)
 * - apply_filter(string $hook, $value, ...$args)
 *
 * Implementation notes:
 * - callbacks stored as [ 'callback' => callable, 'accepted_args' => int, 'name' => ?string ]
 * - flattened & sorted caches are built per-hook for performance and invalidated on changes
 */
final class Hooks
{
    /** @var array<string,array<int,array<string,callable|array>>> */
    private static array $actions = [];

    /** @var array<string,array<int,array<string,callable|array>>> */
    private static array $filters = [];

    /**
     * Flat cached list for actions: ['hook' => [ ['callback'=>callable,'accepted_args'=>int], ... ] ]
     * @var array<string,array<int,array{callback:callable,accepted_args:int}>>
     */
    private static array $flatActions = [];

    /**
     * Flat cached list for filters
     * @var array<string,array<int,array{callback:callable,accepted_args:int}>>
     */
    private static array $flatFilters = [];

    /**
     * Register an action.
     *
     * @param string $hook
     * @param callable $callback
     * @param int $priority
     * @param string|null $name optional unique name for removal/replacement
     * @param int $accepted_args how many args from do_action to pass to callback
     */
    public static function add_action(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1): void
    {
        if (!isset(self::$actions[$hook])) {
            self::$actions[$hook] = [];
        }
        if (!isset(self::$actions[$hook][$priority])) {
            self::$actions[$hook][$priority] = [];
        }

        $entry = [
            'callback' => $callback,
            'accepted_args' => max(0, (int)$accepted_args),
            'name' => $name,
        ];

        if ($name !== null) {
            // named registration: replace existing with same name at same priority
            self::$actions[$hook][$priority][$name] = $entry;
        } else {
            // numeric key (append)
            self::$actions[$hook][$priority][] = $entry;
        }

        // invalidate flat cache for this hook
        unset(self::$flatActions[$hook]);
    }

    /**
     * Fire an action: call all callbacks for the hook with provided args.
     *
     * @param string $hook
     * @param mixed ...$args
     */
    public static function do_action(string $hook, ...$args): void
    {
        if (!isset(self::$actions[$hook])) {
            return;
        }

        if (!isset(self::$flatActions[$hook])) {
            self::$flatActions[$hook] = self::buildFlatList(self::$actions[$hook]);
        }

        foreach (self::$flatActions[$hook] as $entry) {
            $cb = $entry['callback'];
            $accept = $entry['accepted_args'];
            // slice arguments according to accepted_args
            $toPass = $accept > 0 ? array_slice($args, 0, $accept) : [];
            call_user_func_array($cb, $toPass);
        }
    }

    /**
     * Register a filter.
     *
     * @param string $hook
     * @param callable $callback
     * @param int $priority
     * @param string|null $name
     * @param int $accepted_args
     */
    public static function add_filter(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1): void
    {
        if (!isset(self::$filters[$hook])) {
            self::$filters[$hook] = [];
        }
        if (!isset(self::$filters[$hook][$priority])) {
            self::$filters[$hook][$priority] = [];
        }

        $entry = [
            'callback' => $callback,
            'accepted_args' => max(0, (int)$accepted_args),
            'name' => $name,
        ];

        if ($name !== null) {
            self::$filters[$hook][$priority][$name] = $entry;
        } else {
            self::$filters[$hook][$priority][] = $entry;
        }

        unset(self::$flatFilters[$hook]);
    }

    /**
     * Apply filters to a value (pipe $value through callbacks).
     *
     * @param string $hook
     * @param mixed $value
     * @param mixed ...$args additional args passed to filters after $value
     * @return mixed filtered value
     */
    public static function apply_filter(string $hook, $value, ...$args)
    {
        if (!isset(self::$filters[$hook])) {
            return $value;
        }

        if (!isset(self::$flatFilters[$hook])) {
            self::$flatFilters[$hook] = self::buildFlatList(self::$filters[$hook]);
        }

        foreach (self::$flatFilters[$hook] as $entry) {
            $cb = $entry['callback'];
            $accept = $entry['accepted_args'];
            // first arg is current $value
            $allArgs = array_merge([$value], $args);
            $toPass = $accept > 0 ? array_slice($allArgs, 0, $accept) : [];
            // call and update $value with first returned param (convention)
            $result = call_user_func_array($cb, $toPass);
            $value = $result;
        }

        return $value;
    }

    /**
     * Remove a named action added with a name.
     *
     * @param string $hook
     * @param string $name
     * @return bool true if removed
     */
    public static function remove_action_by_name(string $hook, string $name): bool
    {
        if (!isset(self::$actions[$hook])) {
            return false;
        }
        $removed = false;
        foreach (self::$actions[$hook] as $priority => $bucket) {
            if (array_key_exists($name, $bucket)) {
                unset(self::$actions[$hook][$priority][$name]);
                $removed = true;
                if (empty(self::$actions[$hook][$priority])) {
                    unset(self::$actions[$hook][$priority]);
                }
            }
        }
        if ($removed) {
            unset(self::$flatActions[$hook]);
        }
        return $removed;
    }

    /**
     * Remove a named filter added with a name.
     *
     * @param string $hook
     * @param string $name
     * @return bool
     */
    public static function remove_filter_by_name(string $hook, string $name): bool
    {
        if (!isset(self::$filters[$hook])) {
            return false;
        }
        $removed = false;
        foreach (self::$filters[$hook] as $priority => $bucket) {
            if (array_key_exists($name, $bucket)) {
                unset(self::$filters[$hook][$priority][$name]);
                $removed = true;
                if (empty(self::$filters[$hook][$priority])) {
                    unset(self::$filters[$hook][$priority]);
                }
            }
        }
        if ($removed) {
            unset(self::$flatFilters[$hook]);
        }
        return $removed;
    }

    /**
     * Check whether any action exists for hook.
     *
     * @param string $hook
     * @return bool
     */
    public static function has_action(string $hook): bool
    {
        return !empty(self::$actions[$hook]);
    }

    /**
     * Check whether any filter exists for hook.
     *
     * @param string $hook
     * @return bool
     */
    public static function has_filter(string $hook): bool
    {
        return !empty(self::$filters[$hook]);
    }

    /**
     * Build a flat, ordered list from a priority-indexed bucket.
     *
     * Input: [ priority => [ key => entry, key2 => entry, ... ], ... ]
     * Output: [ entry, entry, ... ] ordered by ascending priority and insertion order within same priority.
     *
     * @param array<int,array> $byPriority
     * @return array<int,array{callback:callable,accepted_args:int}>
     */
    private static function buildFlatList(array $byPriority): array
    {
        if (empty($byPriority)) {
            return [];
        }

        ksort($byPriority, SORT_NUMERIC);

        $flat = [];
        foreach ($byPriority as $priority => $bucket) {
            foreach ($bucket as $entry) {
                // ensure structure normalization
                if (is_array($entry) && isset($entry['callback'])) {
                    $flat[] = [
                        'callback' => $entry['callback'],
                        'accepted_args' => (int)($entry['accepted_args'] ?? 1),
                    ];
                }
            }
        }

        return $flat;
    }
}