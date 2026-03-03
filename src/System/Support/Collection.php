<?php
declare(strict_types=1);

namespace System\Support;

/**
 * Collection — Fluent wrapper for working with arrays of data.
 *
 * Provides chainable methods for filtering, mapping, sorting, grouping,
 * and aggregating data. Eliminates boilerplate loops in controllers and views.
 *
 * Usage:
 *   $names = collect($users)->pluck('name')->unique()->sort()->values();
 *   $byRole = collect($users)->groupBy('role');
 *   $total  = collect($orders)->where('status', 'paid')->sum('amount');
 */
class Collection implements \Countable, \IteratorAggregate, \JsonSerializable, \ArrayAccess
{
    /** @var array<int|string, mixed> */
    protected array $items;

    /**
     * @param array<int|string, mixed>|self $items
     */
    public function __construct(array|self $items = [])
    {
        $this->items = $items instanceof self ? $items->all() : $items;
    }

    /**
     * Create a new collection instance.
     *
     * @param array<int|string, mixed>|self $items
     */
    public static function make(array|self $items = []): static
    {
        return new static($items);
    }

    /**
     * Get all items as a plain array.
     *
     * @return array<int|string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Get all items as a plain array (alias for all()).
     *
     * @return array<int|string, mixed>
     */
    public function toArray(): array
    {
        return array_map(static function ($item) {
            if ($item instanceof self) {
                return $item->toArray();
            }
            if ($item instanceof \JsonSerializable) {
                return $item->jsonSerialize();
            }
            if (is_object($item) && method_exists($item, 'toArray')) {
                return $item->toArray();
            }
            return $item;
        }, $this->items);
    }

    /**
     * Get the collection as JSON.
     */
    public function toJson(int $options = 0): string
    {
        return (string) json_encode($this->jsonSerialize(), $options);
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /* ========================================================================
     * Retrieving Items
     * ======================================================================== */

    /**
     * Get the first item (optionally matching a callback).
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            foreach ($this->items as $item) {
                return $item;
            }
            return $default;
        }

        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                return $item;
            }
        }

        return $default;
    }

    /**
     * Get the first item matching a key/value pair.
     */
    public function firstWhere(string $key, mixed $operator = null, mixed $value = null): mixed
    {
        return $this->where($key, $operator, $value)->first();
    }

    /**
     * Get the last item (optionally matching a callback).
     */
    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        $items = $this->items;

        if ($callback === null) {
            if ($items === []) {
                return $default;
            }
            return end($items);
        }

        return (new static(array_reverse($items, true)))->first($callback, $default);
    }

    /**
     * Get an item by key.
     */
    public function get(int|string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->items) ? $this->items[$key] : $default;
    }

    /**
     * Pull an item by key (get and remove).
     */
    public function pull(int|string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        unset($this->items[$key]);
        return $value;
    }

    /* ========================================================================
     * Transformations
     * ======================================================================== */

    /**
     * Run a map over each item.
     *
     * @param callable(mixed $item, int|string $key): mixed $callback
     */
    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);
        $items = array_map($callback, $this->items, $keys);
        return new static(array_combine($keys, $items));
    }

    /**
     * Run a filter over each item.
     *
     * @param callable(mixed $item, int|string $key): bool|null $callback
     */
    public function filter(?callable $callback = null): static
    {
        if ($callback === null) {
            return new static(array_filter($this->items));
        }
        return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Return items that do NOT match the callback.
     */
    public function reject(callable $callback): static
    {
        return $this->filter(fn($item, $key) => !$callback($item, $key));
    }

    /**
     * Reduce the collection to a single value.
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        $result = $initial;
        foreach ($this->items as $key => $item) {
            $result = $callback($result, $item, $key);
        }
        return $result;
    }

    /**
     * Execute a callback on each item (returns the collection for chaining).
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }
        return $this;
    }

    /**
     * Pluck a single column/key from each item.
     *
     * @param string|int $value The key to pluck.
     * @param string|int|null $key Optional key to use as the array key.
     */
    public function pluck(string|int $value, string|int|null $key = null): static
    {
        $results = [];

        foreach ($this->items as $item) {
            $itemValue = self::dataGet($item, $value);

            if ($key === null) {
                $results[] = $itemValue;
            } else {
                $itemKey = self::dataGet($item, $key);
                $results[$itemKey] = $itemValue;
            }
        }

        return new static($results);
    }

    /**
     * Flatten the collection into a single dimension.
     */
    public function flatten(int $depth = INF): static
    {
        return new static(self::flattenArray($this->items, $depth));
    }

    /**
     * Collapse an array of arrays into a single flat array.
     */
    public function collapse(): static
    {
        $results = [];
        foreach ($this->items as $item) {
            if ($item instanceof self) {
                $item = $item->all();
            }
            if (!is_array($item)) {
                continue;
            }
            $results = array_merge($results, $item);
        }
        return new static($results);
    }

    /**
     * Chunk the collection into smaller pieces.
     */
    public function chunk(int $size): static
    {
        if ($size <= 0) {
            return new static();
        }

        $chunks = [];
        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    /**
     * Reset keys to consecutive integers.
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Get the keys of the collection items.
     */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    /**
     * Flip keys and values.
     */
    public function flip(): static
    {
        return new static(array_flip($this->items));
    }

    /**
     * Reverse the collection order.
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    /**
     * Take the first or last N items.
     */
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return new static(array_slice($this->items, $limit, abs($limit)));
        }
        return new static(array_slice($this->items, 0, $limit));
    }

    /**
     * Skip the first N items.
     */
    public function skip(int $count): static
    {
        return new static(array_slice($this->items, $count));
    }

    /**
     * Slice the underlying collection array.
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    /* ========================================================================
     * Filtering / Where Clauses
     * ======================================================================== */

    /**
     * Filter items by a key/value pair.
     *
     * Supports:
     *   ->where('status', 'active')          shorthand for '='
     *   ->where('age', '>=', 18)
     */
    public function where(string $key, mixed $operator = null, mixed $value = null): static
    {
        // where('key', 'value') shorthand
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->filter(function ($item) use ($key, $operator, $value) {
            $itemValue = self::dataGet($item, $key);

            return match ($operator) {
                '='  , '==' => $itemValue == $value,
                '===' => $itemValue === $value,
                '!=' , '<>' => $itemValue != $value,
                '!==' => $itemValue !== $value,
                '<'   => $itemValue < $value,
                '<='  => $itemValue <= $value,
                '>'   => $itemValue > $value,
                '>='  => $itemValue >= $value,
                default => $itemValue == $value,
            };
        });
    }

    /**
     * Filter items where a key is in a set of values.
     */
    public function whereIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => in_array(self::dataGet($item, $key), $values, false));
    }

    /**
     * Filter items where a key is NOT in a set of values.
     */
    public function whereNotIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => !in_array(self::dataGet($item, $key), $values, false));
    }

    /**
     * Filter items where a key is null.
     */
    public function whereNull(string $key): static
    {
        return $this->where($key, '===', null);
    }

    /**
     * Filter items where a key is NOT null.
     */
    public function whereNotNull(string $key): static
    {
        return $this->where($key, '!==', null);
    }

    /**
     * Filter items where a key is between two values (inclusive).
     */
    public function whereBetween(string $key, array $range): static
    {
        return $this->filter(function ($item) use ($key, $range) {
            $val = self::dataGet($item, $key);
            return $val >= $range[0] && $val <= $range[1];
        });
    }

    /**
     * Check if the collection contains a given item or passes a truth test.
     */
    public function contains(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        if (func_num_args() === 1) {
            if (is_callable($key)) {
                foreach ($this->items as $k => $item) {
                    if ($key($item, $k)) {
                        return true;
                    }
                }
                return false;
            }
            return in_array($key, $this->items, false);
        }

        return $this->where(...func_get_args())->isNotEmpty();
    }

    /* ========================================================================
     * Sorting
     * ======================================================================== */

    /**
     * Sort the collection by a callback or key.
     */
    public function sortBy(string|callable $keyOrCallback, int $options = SORT_REGULAR, bool $descending = false): static
    {
        $items = $this->items;

        if (is_callable($keyOrCallback)) {
            uasort($items, function ($a, $b) use ($keyOrCallback, $descending) {
                $va = $keyOrCallback($a);
                $vb = $keyOrCallback($b);
                return $descending ? ($vb <=> $va) : ($va <=> $vb);
            });
        } else {
            uasort($items, function ($a, $b) use ($keyOrCallback, $descending) {
                $va = self::dataGet($a, $keyOrCallback);
                $vb = self::dataGet($b, $keyOrCallback);
                return $descending ? ($vb <=> $va) : ($va <=> $vb);
            });
        }

        return new static($items);
    }

    /**
     * Sort the collection in descending order by a key/callback.
     */
    public function sortByDesc(string|callable $keyOrCallback, int $options = SORT_REGULAR): static
    {
        return $this->sortBy($keyOrCallback, $options, true);
    }

    /**
     * Sort the collection values.
     */
    public function sort(?callable $callback = null): static
    {
        $items = $this->items;

        if ($callback !== null) {
            uasort($items, $callback);
        } else {
            asort($items);
        }

        return new static($items);
    }

    /**
     * Sort by keys.
     */
    public function sortKeys(int $options = SORT_REGULAR, bool $descending = false): static
    {
        $items = $this->items;
        $descending ? krsort($items, $options) : ksort($items, $options);
        return new static($items);
    }

    /* ========================================================================
     * Grouping / Keying
     * ======================================================================== */

    /**
     * Group items by a key or callback.
     */
    public function groupBy(string|callable $groupBy): static
    {
        $results = [];

        foreach ($this->items as $key => $item) {
            $groupKey = is_callable($groupBy)
                ? $groupBy($item, $key)
                : self::dataGet($item, $groupBy);

            $results[$groupKey][] = $item;
        }

        return new static(array_map(fn($group) => new static($group), $results));
    }

    /**
     * Key the collection by a field.
     */
    public function keyBy(string|callable $keyBy): static
    {
        $results = [];

        foreach ($this->items as $key => $item) {
            $resolvedKey = is_callable($keyBy)
                ? $keyBy($item, $key)
                : self::dataGet($item, $keyBy);

            $results[$resolvedKey] = $item;
        }

        return new static($results);
    }

    /**
     * Return unique items.
     */
    public function unique(?string $key = null): static
    {
        if ($key === null) {
            return new static(array_unique($this->items, SORT_REGULAR));
        }

        $seen = [];
        return $this->filter(function ($item) use ($key, &$seen) {
            $val = self::dataGet($item, $key);
            if (in_array($val, $seen, true)) {
                return false;
            }
            $seen[] = $val;
            return true;
        });
    }

    /* ========================================================================
     * Combining / Merging
     * ======================================================================== */

    /**
     * Merge items into the collection.
     */
    public function merge(array|self $items): static
    {
        if ($items instanceof self) {
            $items = $items->all();
        }
        return new static(array_merge($this->items, $items));
    }

    /**
     * Combine the collection (keys) with the given values.
     */
    public function combine(array|self $values): static
    {
        if ($values instanceof self) {
            $values = $values->all();
        }
        return new static(array_combine($this->items, $values));
    }

    /**
     * Union the collection with the given items.
     */
    public function union(array|self $items): static
    {
        if ($items instanceof self) {
            $items = $items->all();
        }
        return new static($this->items + $items);
    }

    /**
     * Get only the specified keys.
     */
    public function only(array $keys): static
    {
        return new static(array_intersect_key($this->items, array_flip($keys)));
    }

    /**
     * Get all except the specified keys.
     */
    public function except(array $keys): static
    {
        return new static(array_diff_key($this->items, array_flip($keys)));
    }

    /**
     * Intersect the collection with the given items.
     */
    public function intersect(array|self $items): static
    {
        if ($items instanceof self) {
            $items = $items->all();
        }
        return new static(array_intersect($this->items, $items));
    }

    /**
     * Get the items whose keys are not present in the given items.
     */
    public function diff(array|self $items): static
    {
        if ($items instanceof self) {
            $items = $items->all();
        }
        return new static(array_diff($this->items, $items));
    }

    /**
     * Push one or more items onto the end of the collection.
     */
    public function push(mixed ...$values): static
    {
        foreach ($values as $v) {
            $this->items[] = $v;
        }
        return $this;
    }

    /**
     * Put an item by key.
     */
    public function put(int|string $key, mixed $value): static
    {
        $this->items[$key] = $value;
        return $this;
    }

    /**
     * Prepend an item to the collection.
     */
    public function prepend(mixed $value, int|string|null $key = null): static
    {
        if ($key === null) {
            array_unshift($this->items, $value);
        } else {
            $this->items = [$key => $value] + $this->items;
        }
        return $this;
    }

    /* ========================================================================
     * Aggregates
     * ======================================================================== */

    /**
     * Get the sum of a given key.
     */
    public function sum(string|callable|null $callback = null): int|float
    {
        if ($callback === null) {
            return array_sum($this->items);
        }

        $total = 0;
        foreach ($this->items as $key => $item) {
            $total += is_callable($callback)
                ? $callback($item, $key)
                : self::dataGet($item, $callback);
        }
        return $total;
    }

    /**
     * Get the average of a given key.
     */
    public function avg(string|callable|null $callback = null): int|float|null
    {
        $count = $this->count();
        if ($count === 0) {
            return null;
        }
        return $this->sum($callback) / $count;
    }

    /**
     * Alias for avg().
     */
    public function average(string|callable|null $callback = null): int|float|null
    {
        return $this->avg($callback);
    }

    /**
     * Get the minimum value of a given key.
     */
    public function min(string|callable|null $callback = null): mixed
    {
        if ($callback === null) {
            return $this->items === [] ? null : min($this->items);
        }

        return $this->map(fn($item, $key) => is_callable($callback)
            ? $callback($item, $key)
            : self::dataGet($item, $callback)
        )->filter()->min();
    }

    /**
     * Get the maximum value of a given key.
     */
    public function max(string|callable|null $callback = null): mixed
    {
        if ($callback === null) {
            return $this->items === [] ? null : max($this->items);
        }

        return $this->map(fn($item, $key) => is_callable($callback)
            ? $callback($item, $key)
            : self::dataGet($item, $callback)
        )->filter()->max();
    }

    /**
     * Get the median of a given key.
     */
    public function median(string|callable|null $callback = null): int|float|null
    {
        $values = $callback !== null
            ? $this->pluck($callback)->sort()->values()->all()
            : (new static(array_values($this->items)))->sort()->values()->all();

        $count = count($values);
        if ($count === 0) {
            return null;
        }

        $mid = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }

        return $values[$mid];
    }

    /* ========================================================================
     * Counting / Emptiness
     * ======================================================================== */

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Count the occurrences of values (or by callback/key).
     */
    public function countBy(string|callable|null $callback = null): static
    {
        if ($callback === null) {
            return new static(array_count_values($this->items));
        }

        $counts = [];
        foreach ($this->items as $key => $item) {
            $groupKey = is_callable($callback)
                ? $callback($item, $key)
                : self::dataGet($item, $callback);

            $counts[$groupKey] = ($counts[$groupKey] ?? 0) + 1;
        }

        return new static($counts);
    }

    /* ========================================================================
     * String / Output
     * ======================================================================== */

    /**
     * Join items with a separator.
     */
    public function implode(string|callable $valueOrGlue, ?string $glue = null): string
    {
        if ($glue !== null) {
            // implode('name', ', ') — pluck first, then join
            return $this->pluck($valueOrGlue)->implode($glue);
        }

        // implode(', ') — join values directly
        return implode($valueOrGlue, $this->items);
    }

    /* ========================================================================
     * Higher Order
     * ======================================================================== */

    /**
     * Apply a callback to the collection and return the result.
     */
    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    /**
     * Apply a callback and return the collection itself (for debugging/logging).
     */
    public function tap(callable $callback): static
    {
        $callback(new static($this->items));
        return $this;
    }

    /**
     * Transform each item by replacing items with callback result.
     */
    public function transform(callable $callback): static
    {
        $this->items = $this->map($callback)->all();
        return $this;
    }

    /**
     * Pad the collection to the specified length.
     */
    public function pad(int $size, mixed $value): static
    {
        return new static(array_pad($this->items, $size, $value));
    }

    /**
     * Zip one or more arrays with this collection.
     */
    public function zip(array ...$arrays): static
    {
        $params = array_merge([$this->items], $arrays);
        return new static(array_map(null, ...$params));
    }

    /* ========================================================================
     * Interfaces: IteratorAggregate, ArrayAccess
     * ======================================================================== */

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    /* ========================================================================
     * Internal Helpers
     * ======================================================================== */

    /**
     * Get a value from a nested item (array or object) using a key.
     */
    protected static function dataGet(mixed $target, string|int $key, mixed $default = null): mixed
    {
        if ($target === null) {
            return $default;
        }

        $key = (string) $key;

        // Direct key access (no dot)
        if (!str_contains($key, '.')) {
            if (is_array($target)) {
                return array_key_exists($key, $target) ? $target[$key] : $default;
            }
            if (is_object($target)) {
                return $target->{$key} ?? $default;
            }
            return $default;
        }

        // Dot notation traversal
        foreach (explode('.', $key) as $segment) {
            if (is_array($target) && array_key_exists($segment, $target)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return $default;
            }
        }

        return $target;
    }

    /**
     * Recursively flatten an array.
     */
    private static function flattenArray(array $array, float|int $depth): array
    {
        $result = [];

        foreach ($array as $item) {
            if ($item instanceof self) {
                $item = $item->all();
            }

            if (!is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 1) {
                $result = array_merge($result, array_values($item));
            } elseif ($depth > 1 || $depth === INF) {
                $result = array_merge($result, self::flattenArray($item, $depth - 1));
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }
}
