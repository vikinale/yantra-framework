# Collections

`System\Support\Collection` is a fluent, chainable wrapper around PHP arrays. It eliminates boilerplate loops in controllers and views by providing expressive methods for filtering, mapping, sorting, grouping, and aggregating data — items can be plain values, associative arrays, or objects, and key lookups support dot notation (`'address.city'`). Almost every method returns a **new** collection, so chains never corrupt your source data. This page covers the most useful methods; the full API lives in [the API reference](/api/collection).

```php
$rows = Order::query()->get();   // query-builder rows (associative arrays)

$topCustomers = collect($rows)
    ->where('status', 'paid')
    ->groupBy('customer_id')
    ->map(fn($orders) => $orders->sum('amount'))
    ->sortByDesc(fn($total) => $total)
    ->take(10);
```

## Creating Collections

```php
use System\Support\Collection;

$c = collect([1, 2, 3]);              // global helper
$c = new Collection(['a' => 1]);      // constructor
$c = Collection::make($otherCollection); // static factory; accepts array or Collection
```

Both the constructor and `make()` accept an `array` or another `Collection` (which is unwrapped via `all()`).

## Retrieving Items

```php
$c = collect(['a' => 1, 'b' => 2, 'c' => 3]);

$c->all();                        // ['a' => 1, 'b' => 2, 'c' => 3]
$c->first();                      // 1
$c->first(fn($v) => $v > 1);      // 2
$c->last();                       // 3
$c->get('b');                     // 2
$c->get('z', 'default');          // 'default'
$c->pull('a');                    // 1 — and REMOVES 'a' from the collection
$users->firstWhere('role', 'admin');   // first item matching key/value
```

- `first(?callable $callback = null, mixed $default = null)` / `last(?callable $callback = null, mixed $default = null)` — optionally accept a truth test.
- `get(int|string $key, mixed $default = null)` — retrieve by key.
- `pull(int|string $key, mixed $default = null)` — retrieve and remove (**mutates** the collection).
- `firstWhere(string $key, mixed $operator = null, mixed $value = null)` — shorthand for `where(...)->first()`.

## Filtering

```php
$users->where('status', 'active');            // shorthand for '='
$users->where('age', '>=', 18);               // supports = == === != <> !== < <= > >=
$users->whereIn('role', ['admin', 'editor']);
$users->whereNotIn('role', ['banned']);
$users->whereNull('deleted_at');
$users->whereNotNull('email_verified_at');
$users->whereBetween('age', [18, 65]);

$c->filter(fn($v, $k) => $v > 10);   // keep matching items
$c->filter();                        // remove falsy values
$c->reject(fn($v) => $v > 10);       // inverse of filter

$c->contains(3);                     // value check (loose)
$c->contains(fn($v) => $v > 100);    // truth test
$users->contains('role', 'admin');   // key/value check
```

Filtering preserves original keys — chain `->values()` if you need a re-indexed list.

## Transformation

```php
$c->map(fn($v, $k) => $v * 2);            // new collection, keys preserved
$users->pluck('name');                    // extract one column
$users->pluck('email', 'id');             // values keyed by another column
$users->keyBy('id');                      // re-key items by a field
$users->groupBy('role');                  // Collection of Collections
$users->groupBy(fn($u) => $u['age'] > 30 ? 'senior' : 'junior');

$c->keys();          $c->values();        // keys / re-indexed values
$c->flip();          $c->reverse();       // reverse preserves keys
$c->unique();        $users->unique('email');
$c->chunk(100);                           // Collection of Collections of ≤100 items
$c->flatten();       $c->flatten(1);      // flatten nested arrays (optional depth)
$c->collapse();                           // merge an array-of-arrays one level
$c->take(5);         $c->take(-5);        // first / last N
$c->skip(10);                             // drop the first N
$c->slice(10, 5);                         // offset + length, keys preserved
$c->pad(5, 0);                            // pad to length with a value
$c->zip([4, 5, 6]);                       // pair items with other arrays
```

### Sorting

```php
$c->sort();                               // ascending by value (asort, keys kept)
$c->sort(fn($a, $b) => $b <=> $a);        // custom comparator
$users->sortBy('name');                   // by key (dot notation supported)
$users->sortBy(fn($u) => $u['last_login']);
$users->sortByDesc('created_at');
$c->sortKeys();                           // by key name
```

## Aggregation

```php
$orders->count();                 // number of items
$orders->sum('amount');           // by key, callback, or no argument for raw values
$orders->avg('amount');           // null when empty (alias: average())
$orders->min('amount');           // by key/callback, or min of raw values
$orders->max('amount');
$orders->median('amount');        // by key, or of raw values
$orders->countBy('status');       // ['paid' => 12, 'open' => 3]

$c->reduce(fn($carry, $item) => $carry + $item, 0);

$c->isEmpty();     $c->isNotEmpty();
```

## Array Operations

```php
$c->merge(['d' => 4]);                    // new collection (later keys win)
$c->union($defaults);                     // new collection (existing keys win)
$c->combine(['x', 'y']);                  // use items as keys for given values
$c->only(['a', 'b']);                     // keep listed keys
$c->except(['secret']);                   // drop listed keys
$c->diff([2, 3]);                         // values not present in the given items
$c->intersect([2, 3]);                    // values present in both

// These three MUTATE the collection and return $this:
$c->push(4, 5);                           // append one or more values
$c->put('key', 'value');                  // set by key
$c->prepend(0);                           // add to the front (optional key)
```

## Immutability

The Collection is *mostly* immutable: query and transformation methods (`map`, `filter`, `reject`, `where*`, `pluck`, `sort*`, `groupBy`, `keyBy`, `unique`, `merge`, `diff`, `intersect`, `only`, `except`, `chunk`, `flatten`, `take`, `skip`, `slice`, `reverse`, `values`, `keys`, `flip`, `pad`, `zip`, …) all return a **new** collection and leave the original untouched.

A small set of methods mutate the collection in place:

| Method | Effect |
| --- | --- |
| `push(...$values)` | Appends values, returns `$this`. |
| `put($key, $value)` | Sets a key, returns `$this`. |
| `prepend($value, $key = null)` | Adds to the front, returns `$this`. |
| `pull($key)` | Returns the value and removes it. |
| `transform(callable)` | Like `map()`, but replaces the items in place. |
| `offsetSet` / `offsetUnset` | Array-access writes (`$c['k'] = ...`, `unset($c['k'])`). |

`each()` and `tap()` return `$this` without modifying items (`each` stops early if the callback returns `false`; `tap` receives a *copy* of the collection). `pipe(callable)` passes the whole collection to a callback and returns whatever the callback returns.

## Interoperability

Collection implements `ArrayAccess`, `Countable`, `IteratorAggregate`, and `JsonSerializable`, so it behaves like an array almost everywhere:

```php
$c = collect(['name' => 'Yantra']);

$c['name'];                 // ArrayAccess read
$c['version'] = '1.0';      // ArrayAccess write (mutates)
count($c);                  // Countable
foreach ($c as $key => $value) { ... }   // IteratorAggregate
json_encode($c);            // JsonSerializable → same as toArray()

$c->toArray();              // recursive: nested Collections and objects with
                            // toArray()/jsonSerialize() are converted too
$c->toJson(JSON_PRETTY_PRINT);
$c->implode(', ');          // join raw values
$users->implode('name', ', ');   // pluck a key, then join
```

::: warning Gotchas
- `where()` and `contains()` use **loose comparison** (`==`) by default; use the `'==='` operator (`where('id', '===', 7)`) for strict matching. `whereIn()` / `whereNotIn()` are also loose.
- Filtering and sorting **preserve keys**. If you feed the result to `json_encode()` expecting a JSON array, call `->values()` first, or you may get an object with numeric string keys.
- `pull()`, `push()`, `put()`, `prepend()`, and `transform()` mutate the collection — everything else returns a new instance.
- `groupBy()` and `chunk()` return collections *of collections*; call `->toArray()` for fully nested plain arrays.
- `avg()`, `min()`, `max()`, and `median()` return `null` on an empty collection, not `0`.
:::

## Related

- [Helpers](/features/helpers)
- [Query Builder](/database/query-builder)
- [Collection API Reference](/api/collection)
