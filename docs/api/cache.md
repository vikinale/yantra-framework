# Cache

`System\Utilities\Cache`

`Cache` is a static facade over a pluggable cache adapter (`System\Utilities\Cache\CacheAdapterInterface`). Without configuration it lazily creates a `FileCacheAdapter` rooted at `./storage/cache` (relative to the current working directory). Call `Cache::init()` — optionally with a Redis or custom adapter — early in bootstrap to override the default. All operations delegate to the active adapter, so tag support and atomicity guarantees depend on which adapter you use.

```php
use System\Utilities\Cache;

Cache::put('greeting', 'hello', 60);        // 60-second TTL
Cache::get('greeting', 'default');          // 'hello'

$users = Cache::remember('users.list', 300, fn() => User::all()->toArray());
```

## Methods

### Setup

| Method | Returns | Description |
| --- | --- | --- |
| `init(CacheAdapterInterface $adapter = null)` | `void` | Initialize the cache. With no argument (and no adapter yet set) it creates a `FileCacheAdapter` under `getcwd()/storage/cache`. Safe to call once at boot. |
| `setAdapter(CacheAdapterInterface $adapter)` | `void` | Swap the active adapter unconditionally (e.g. in tests). |

### Core operations

| Method | Returns | Description |
| --- | --- | --- |
| `put(string $key, $value, int $ttl = 0)` | `bool` | Store a value. `$ttl` is in **seconds**; `0` means store forever. |
| `get(string $key, $default = null)` | `mixed` | Read a value, or `$default` when the key is missing/expired. |
| `has(string $key)` | `bool` | Whether the key exists (and is unexpired). |
| `forget(string $key)` | `bool` | Delete a single key. |
| `flush()` | `bool` | Clear the entire cache store. |
| `remember(string $key, int $ttl, callable $callback)` | `mixed` | Return the cached value, or run `$callback`, store its result for `$ttl` seconds, and return it. |

### Counters

| Method | Returns | Description |
| --- | --- | --- |
| `increment(string $key, int $amount = 1)` | `int\|false` | Atomically increase a numeric value (adapter-dependent return). |
| `decrement(string $key, int $amount = 1)` | `int\|false` | Atomically decrease a numeric value. |

### Tagging

| Method | Returns | Description |
| --- | --- | --- |
| `putWithTags(string $key, $value, int $ttl, array $tags = [])` | `bool` | Store a value associated with one or more tags. |
| `invalidateTag(string $tag)` | `bool` | Invalidate every key stored under a tag. |

## Examples

### Configure a Redis adapter

```php
$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

Cache::init(new \System\Utilities\Cache\RedisCacheAdapter($redis, 'yantra:cache:'));
Cache::put('page.home', $html, 120);
```

### `remember()` — cache-aside pattern

```php
$stats = Cache::remember('dashboard.stats', 600, function () {
    return [
        'users'  => User::count(),
        'orders' => Order::count(),
    ];
});
```

`remember()` treats a stored `null` as a cache miss (it checks `get(...) !== null`), so it will re-run the callback each time for values that are genuinely `null`.

### Tag-based invalidation

```php
Cache::putWithTags('users.page.1', $page1, 3600, ['users', 'listing']);
Cache::putWithTags('users.page.2', $page2, 3600, ['users', 'listing']);

// A write elsewhere invalidates every cached user listing at once:
Cache::invalidateTag('users');
```

::: warning Gotchas
- **TTL is in seconds, and `0` means forever** — not "expire immediately". Passing `0` to `put()` stores the value indefinitely.
- **`remember()` cannot cache `null`.** Because a stored `null` is indistinguishable from a miss, the callback re-runs every call. Cache a sentinel (e.g. `false` or `[]`) instead.
- **The default file store is relative to `getcwd()`**, not the project root. In CLI contexts the working directory may differ from the web root — call `Cache::init()` with an explicit adapter if you need a fixed path.
- **Tag guarantees are adapter-specific.** The class docblock notes tags are "not guaranteed perfectly atomic across tags"; do not rely on tag invalidation for correctness-critical invariants.
- There is **no `Cache::remember()` variant that never expires beyond passing a large TTL**, and no `pull()` / `add()` / `many()` helpers — the surface is exactly the methods listed above.
:::

## Related

- [Cache guide](/features/cache)
- [Helpers API Reference](/api/helpers)
