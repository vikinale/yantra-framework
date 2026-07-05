# API Reference: Collection

`System\Support\Collection`

A fluent, chainable wrapper around an array of data (arrays, objects, or models). It eliminates loop boilerplate in controllers and views with map/filter/sort/group/aggregate operations, and implements `Countable`, `IteratorAggregate`, `JsonSerializable`, and `ArrayAccess`. Most methods return a **new** `Collection` (immutable-style), so you can chain freely; a handful mutate in place (noted below). Create one with `new Collection($items)`, `Collection::make($items)`, or the `collect()` helper.

```php
$names = collect($users)->pluck('name')->unique()->sort()->values();
$total = collect($orders)->where('status', 'paid')->sum('amount');
```

Nested keys everywhere support **dot notation** through the internal `dataGet()` helper (e.g. `pluck('profile.city')`, `where('user.role', 'admin')`), reading from arrays and objects alike.

## Method Table

### Construction & output

| Signature | Returns | Description |
| --- | --- | --- |
| `__construct(array\|self $items = [])` | — | Wrap an array or another collection. |
| `static make(array\|self $items = []): static` | `static` | Static factory. |
| `all(): array` | `array` | Underlying items as a plain array. |
| `toArray(): array` | `array` | Recursively convert items (collections, `JsonSerializable`, objects with `toArray()`). |
| `toJson(int $options = 0): string` | `string` | JSON-encode the collection. |
| `jsonSerialize(): mixed` | `array` | Delegates to `toArray()`. |

### Retrieving items

| Signature | Returns | Description |
| --- | --- | --- |
| `first(?callable $callback = null, mixed $default = null): mixed` | mixed | First item (optionally matching a predicate). |
| `firstWhere(string $key, mixed $operator = null, mixed $value = null): mixed` | mixed | First item matching a key/value (or key/op/value). |
| `last(?callable $callback = null, mixed $default = null): mixed` | mixed | Last item (optionally matching a predicate). |
| `get(int\|string $key, mixed $default = null): mixed` | mixed | Item by key. |
| `pull(int\|string $key, mixed $default = null): mixed` | mixed | Get **and remove** an item by key (mutates). |

### Transformations

| Signature | Returns | Description |
| --- | --- | --- |
| `map(callable $callback): static` | `static` | Map over items (callback gets `$item, $key`). |
| `filter(?callable $callback = null): static` | `static` | Keep truthy items, or those passing the callback. |
| `reject(callable $callback): static` | `static` | Inverse of `filter()`. |
| `reduce(callable $callback, mixed $initial = null): mixed` | mixed | Fold to a single value. |
| `each(callable $callback): static` | `static` | Iterate (return `false` from callback to break); returns self. |
| `pluck(string\|int $value, string\|int\|null $key = null): static` | `static` | Extract a column, optionally keyed. |
| `flatten(int $depth = INF): static` | `static` | Flatten nested items to one dimension. |
| `collapse(): static` | `static` | Merge an array of arrays into one flat array. |
| `chunk(int $size): static` | `static` | Split into sub-collections of `$size`. |
| `values(): static` | `static` | Reindex to consecutive integer keys. |
| `keys(): static` | `static` | Collection of the item keys. |
| `flip(): static` | `static` | Swap keys and values. |
| `reverse(): static` | `static` | Reverse order (preserving keys). |
| `take(int $limit): static` | `static` | First `$limit` items (or last `$limit` if negative). |
| `skip(int $count): static` | `static` | Drop the first `$count` items. |
| `slice(int $offset, ?int $length = null): static` | `static` | Array slice (preserving keys). |

### Filtering / where clauses

| Signature | Returns | Description |
| --- | --- | --- |
| `where(string $key, mixed $operator = null, mixed $value = null): static` | `static` | Filter by key/value (`where('k','v')`) or key/op/value. |
| `whereIn(string $key, array $values): static` | `static` | Key value is in the set (loose comparison). |
| `whereNotIn(string $key, array $values): static` | `static` | Key value is not in the set. |
| `whereNull(string $key): static` | `static` | Key is strictly `null`. |
| `whereNotNull(string $key): static` | `static` | Key is not `null`. |
| `whereBetween(string $key, array $range): static` | `static` | Key within `[range[0], range[1]]` inclusive. |
| `contains(mixed $key, mixed $operator = null, mixed $value = null): bool` | `bool` | Contains a value, passes a callback, or matches a where. |

### Sorting

| Signature | Returns | Description |
| --- | --- | --- |
| `sortBy(string\|callable $keyOrCallback, int $options = SORT_REGULAR, bool $descending = false): static` | `static` | Sort by a key or callback (keys preserved). |
| `sortByDesc(string\|callable $keyOrCallback, int $options = SORT_REGULAR): static` | `static` | Descending `sortBy()`. |
| `sort(?callable $callback = null): static` | `static` | Sort values (`asort`, or a custom comparator). |
| `sortKeys(int $options = SORT_REGULAR, bool $descending = false): static` | `static` | Sort by key. |

### Grouping / keying / uniqueness

| Signature | Returns | Description |
| --- | --- | --- |
| `groupBy(string\|callable $groupBy): static` | `static` | Group into sub-collections by key/callback. |
| `keyBy(string\|callable $keyBy): static` | `static` | Re-key the collection by a field/callback. |
| `unique(?string $key = null): static` | `static` | Distinct values (optionally by a key). |

### Combining / merging / set ops

| Signature | Returns | Description |
| --- | --- | --- |
| `merge(array\|self $items): static` | `static` | `array_merge` semantics. |
| `combine(array\|self $values): static` | `static` | Use current items as keys, `$values` as values. |
| `union(array\|self $items): static` | `static` | `+` union (left-hand keys win). |
| `only(array $keys): static` | `static` | Keep only the given keys. |
| `except(array $keys): static` | `static` | Drop the given keys. |
| `intersect(array\|self $items): static` | `static` | Values present in both. |
| `diff(array\|self $items): static` | `static` | Values not present in the given items. |
| `push(mixed ...$values): static` | `static` | Append (mutates); returns self. |
| `put(int\|string $key, mixed $value): static` | `static` | Set by key (mutates); returns self. |
| `prepend(mixed $value, int\|string\|null $key = null): static` | `static` | Prepend (mutates); returns self. |

### Aggregates

| Signature | Returns | Description |
| --- | --- | --- |
| `sum(string\|callable\|null $callback = null): int\|float` | number | Sum of values, a key, or a callback. |
| `avg(string\|callable\|null $callback = null): int\|float\|null` | number\|null | Mean (`null` when empty). |
| `average(string\|callable\|null $callback = null): int\|float\|null` | number\|null | Alias for `avg()`. |
| `min(string\|callable\|null $callback = null): mixed` | mixed | Minimum value/key/callback result. |
| `max(string\|callable\|null $callback = null): mixed` | mixed | Maximum value/key/callback result. |
| `median(string\|callable\|null $callback = null): int\|float\|null` | number\|null | Median (`null` when empty). |

### Counting / emptiness

| Signature | Returns | Description |
| --- | --- | --- |
| `count(): int` | `int` | Number of items (`Countable`). |
| `isEmpty(): bool` | `bool` | Whether the collection is empty. |
| `isNotEmpty(): bool` | `bool` | Inverse of `isEmpty()`. |
| `countBy(string\|callable\|null $callback = null): static` | `static` | Count occurrences by value/callback/key. |

### String / higher-order / misc

| Signature | Returns | Description |
| --- | --- | --- |
| `implode(string\|callable $valueOrGlue, ?string $glue = null): string` | `string` | Join values, or pluck-then-join (`implode('name', ', ')`). |
| `pipe(callable $callback): mixed` | mixed | Pass the collection to a callback and return its result. |
| `tap(callable $callback): static` | `static` | Run a callback for side effects; returns self. |
| `transform(callable $callback): static` | `static` | Map **in place** (mutates); returns self. |
| `pad(int $size, mixed $value): static` | `static` | Pad to a length with a value. |
| `zip(array ...$arrays): static` | `static` | Zip with one or more arrays. |

### Interfaces

| Signature | Returns | Description |
| --- | --- | --- |
| `getIterator(): \ArrayIterator` | iterator | `foreach` support (`IteratorAggregate`). |
| `offsetExists/offsetGet/offsetSet/offsetUnset` | — | `ArrayAccess` over the items. |

## Selected examples

### `where` shorthand and operators

Two args means `=`; three args takes an operator. Recognized operators: `=`/`==`, `===`, `!=`/`<>`, `!==`, `<`, `<=`, `>`, `>=` (anything else falls back to loose `==`).

```php
collect($users)->where('active', true);           // '=' shorthand
collect($users)->where('age', '>=', 18);
collect($users)->where('role', '===', 'admin');   // strict
```

### `implode` has two forms

```php
collect(['a', 'b', 'c'])->implode(', ');          // "a, b, c"
collect($users)->implode('name', ', ');            // pluck 'name', then join
```

### Aggregates over a key

```php
$revenue = collect($orders)->sum('amount');
$oldest  = collect($users)->max('age');
$byRole  = collect($users)->groupBy('role');       // Collection of Collections
```

::: warning Gotchas
- **Most methods return a new collection, but `push()`, `put()`, `prepend()`, `pull()`, and `transform()` mutate `$this`** and return the (same) instance. Don't assume immutability for those five.
- **`where` comparisons are loose by default.** Use `===`/`!==` when type matters (e.g. distinguishing `0`, `''`, `false`).
- **`whereIn`/`whereNotIn` also use loose comparison** (`in_array(..., false)`).
- **`unique()` without a key uses `SORT_REGULAR`**, so numerically-equal values of different types may collapse together.
- **`avg`/`median` return `null` on an empty collection**, not `0`.
:::

## Related

- [API Reference: Model](/api/model) — `getModels()` and relations return arrays you can wrap with `collect()`
- [API Reference: QueryBuilder](/api/query-builder)
