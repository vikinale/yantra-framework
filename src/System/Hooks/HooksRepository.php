<?php
declare(strict_types=1);

namespace System\Hooks;

use System\Contracts\HooksInterface;

/**
 * HooksRepository — instance-based action/filter hooks system.
 *
 * Holds all hook registrations as instance state. The static `Hooks`
 * facade delegates to a shared HooksRepository instance, so existing
 * `Hooks::add_action()` / `Hooks::do_action()` calls continue to work.
 */
final class HooksRepository implements HooksInterface
{
    /** @var array<string,array<int,array<string,callable|array>>> */
    private array $actions = [];

    /** @var array<string,array<int,array<string,callable|array>>> */
    private array $filters = [];

    /** @var array<string,array<int,array{callback:callable,accepted_args:int}>> */
    private array $flatActions = [];

    /** @var array<string,array<int,array{callback:callable,accepted_args:int}>> */
    private array $flatFilters = [];

    /* -------------------- Actions -------------------- */

    public function addAction(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $acceptedArgs = 1): void
    {
        if (!isset($this->actions[$hook])) {
            $this->actions[$hook] = [];
        }
        if (!isset($this->actions[$hook][$priority])) {
            $this->actions[$hook][$priority] = [];
        }

        $entry = [
            'callback'      => $callback,
            'accepted_args' => max(0, $acceptedArgs),
            'name'          => $name,
        ];

        if ($name !== null) {
            $this->actions[$hook][$priority][$name] = $entry;
        } else {
            $this->actions[$hook][$priority][] = $entry;
        }

        unset($this->flatActions[$hook]);
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        if (!isset($this->actions[$hook])) {
            return;
        }

        if (!isset($this->flatActions[$hook])) {
            $this->flatActions[$hook] = self::buildFlatList($this->actions[$hook]);
        }

        foreach ($this->flatActions[$hook] as $entry) {
            $cb     = $entry['callback'];
            $accept = $entry['accepted_args'];
            $toPass = $accept > 0 ? array_slice($args, 0, $accept) : [];
            call_user_func_array($cb, $toPass);
        }
    }

    /* -------------------- Filters -------------------- */

    public function addFilter(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $acceptedArgs = 1): void
    {
        if (!isset($this->filters[$hook])) {
            $this->filters[$hook] = [];
        }
        if (!isset($this->filters[$hook][$priority])) {
            $this->filters[$hook][$priority] = [];
        }

        $entry = [
            'callback'      => $callback,
            'accepted_args' => max(0, $acceptedArgs),
            'name'          => $name,
        ];

        if ($name !== null) {
            $this->filters[$hook][$priority][$name] = $entry;
        } else {
            $this->filters[$hook][$priority][] = $entry;
        }

        unset($this->flatFilters[$hook]);
    }

    public function applyFilter(string $hook, mixed $value, mixed ...$args): mixed
    {
        if (!isset($this->filters[$hook])) {
            return $value;
        }

        if (!isset($this->flatFilters[$hook])) {
            $this->flatFilters[$hook] = self::buildFlatList($this->filters[$hook]);
        }

        foreach ($this->flatFilters[$hook] as $entry) {
            $cb     = $entry['callback'];
            $accept = $entry['accepted_args'];
            $allArgs = array_merge([$value], $args);
            $toPass  = $accept > 0 ? array_slice($allArgs, 0, $accept) : [];
            $value   = call_user_func_array($cb, $toPass);
        }

        return $value;
    }

    /* -------------------- Removal -------------------- */

    public function removeActionByName(string $hook, string $name): bool
    {
        if (!isset($this->actions[$hook])) {
            return false;
        }
        $removed = false;
        foreach ($this->actions[$hook] as $priority => $bucket) {
            if (array_key_exists($name, $bucket)) {
                unset($this->actions[$hook][$priority][$name]);
                $removed = true;
                if (empty($this->actions[$hook][$priority])) {
                    unset($this->actions[$hook][$priority]);
                }
            }
        }
        if ($removed) {
            unset($this->flatActions[$hook]);
        }
        return $removed;
    }

    public function removeFilterByName(string $hook, string $name): bool
    {
        if (!isset($this->filters[$hook])) {
            return false;
        }
        $removed = false;
        foreach ($this->filters[$hook] as $priority => $bucket) {
            if (array_key_exists($name, $bucket)) {
                unset($this->filters[$hook][$priority][$name]);
                $removed = true;
                if (empty($this->filters[$hook][$priority])) {
                    unset($this->filters[$hook][$priority]);
                }
            }
        }
        if ($removed) {
            unset($this->flatFilters[$hook]);
        }
        return $removed;
    }

    /* -------------------- Inspection -------------------- */

    public function hasAction(string $hook): bool
    {
        return !empty($this->actions[$hook]);
    }

    public function hasFilter(string $hook): bool
    {
        return !empty($this->filters[$hook]);
    }

    /* -------------------- Reset -------------------- */

    public function reset(): void
    {
        $this->actions     = [];
        $this->filters     = [];
        $this->flatActions = [];
        $this->flatFilters = [];
    }

    /* -------------------- Internal -------------------- */

    /**
     * Build a flat, priority-ordered list from a priority-indexed bucket.
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
        foreach ($byPriority as $bucket) {
            foreach ($bucket as $entry) {
                if (is_array($entry) && isset($entry['callback'])) {
                    $flat[] = [
                        'callback'      => $entry['callback'],
                        'accepted_args' => (int) ($entry['accepted_args'] ?? 1),
                    ];
                }
            }
        }

        return $flat;
    }
}
