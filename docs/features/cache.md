# Cache

Yantra ships a static `Cache` facade backed by pluggable adapters (`System\Utilities\Cache`). Values are stored with an optional TTL, can be tagged for group invalidation, and the facade exposes atomic-style counters plus the classic `remember` pattern for memoizing expensive computations. Two adapters are included out of the box — file-based (zero setup) and Redis — and a small `RequestCache` wrapper gives you an injectable, prefixable instance API right off the `Request` object.

```php
use System\Utilities\Cache;

Cache::init(); // default FileCacheAdapter under ./storage/cache

Cache::put('user.1', $user, 3600);          // TTL in seconds, 0 = forever
$user = Cache::get('user.1');               // null if missing
$user = Cache::get('user.1', $fallback);    // with default

$users = Cache::remember('active_users', 3600, function () {
    return User::where('is_active', true)->get();
});
```

## Initialization & Adapters

`Cache::init()` accepts an optional adapter. Called with no arguments, it lazily creates a `FileCacheAdapter` rooted at `./storage/cache` (relative to the current working directory). Every other facade method calls `init()` automatically if no adapter is set, so explicit initialization is only required when you want a non-default adapter.

```php
use System\Utilities\Cache;
use System\Utilities\Cache\FileCacheAdapter;
use System\Utilities\Cache\RedisCacheAdapter;

// File adapter with an explicit base directory
Cache::init(new FileCacheAdapter('/var/www/app/storage/cache'));

// Redis adapter
$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);
Cache::init(new RedisCacheAdapter($redis, 'yantra:cache:'));

// Swap the adapter later (e.g. in tests)
Cache::setAdapter(new FileCacheAdapter(sys_get_temp_dir() . '/test-cache'));
```

| Adapter | Constructor | Notes |
|---|---|---|
| `FileCacheAdapter` | `new FileCacheAdapter(string $baseDir)` | Serialized files in a two-level sha256 directory layout; uses `flock()` for concurrent access. No infrastructure required. |
| `RedisCacheAdapter` | `new RedisCacheAdapter($client, string $prefix = 'yantra:cache:', int $defaultTtl = 0)` | `$client` is a `\Redis` instance (or any object with the same methods). Keys are prefixed for isolation. |

Both adapters implement `System\Utilities\Cache\CacheAdapterInterface` (`put`, `get`, `has`, `forget`, `increment`, `decrement`, `flush`, `putWithTags`, `invalidateTag`), so you can write your own adapter against the same contract.

## Core Operations

```php
Cache::put('key', $value, 60);   // store for 60 seconds (0 = no expiry) — returns bool
$v = Cache::get('key', 'dflt');  // fetch with default
Cache::has('key');               // bool
Cache::forget('key');            // remove one key
Cache::flush();                  // clear everything
```

### Remember

`Cache::remember($key, $ttl, $callback)` returns the cached value if present; on a miss it runs the callback, stores the result with the given TTL, and returns it:

```php
$report = Cache::remember('monthly.report', 86400, fn () => buildExpensiveReport());
```

### Counters

```php
Cache::increment('page.views');        // +1
Cache::increment('api.calls', 5);      // +5
Cache::decrement('stock.item42');      // -1
Cache::decrement('credits', 10);       // -10
```

Both delegate to the adapter and default to an amount of `1`.

## Tags

Tags let you invalidate a group of related keys in one call:

```php
Cache::putWithTags('user.list', $users, 3600, ['users', 'list']);
Cache::putWithTags('user.1', $user, 3600, ['users']);

Cache::invalidateTag('users'); // clears both entries above
```

## RequestCache

`System\Utilities\RequestCache` is a thin instance-based wrapper around the `Cache` facade, available directly from the request via `Request::cache()`. It exists so controllers and middleware can depend on an injectable object instead of static calls, and it supports an optional key prefix for scoping:

```php
public function index()
{
    $value = $this->req()->cache()->remember('users.all', 300, fn () => User::all());
}
```

```php
use System\Utilities\RequestCache;

$cache = new RequestCache('reports');   // all keys become "reports:<key>"
$cache->put('daily', $data, 600);       // stored as "reports:daily"

$scoped = $cache->withPrefix('admin');  // new instance with a different prefix
```

`RequestCache` mirrors the facade API: `put`, `get`, `has`, `forget`, `remember`, `increment`, `decrement`, `flush`, `putWithTags`, `invalidateTag` — all applying the prefix to keys (tags are **not** prefixed).

## CLI

```bash
php yantra cache:clear
```

The `cache:clear` command flushes the application cache directories — it removes files under `storage/cache/routes` and `storage/cache/views`.

::: warning Gotchas
- `remember()` treats `null` as a miss (`Cache::get($key, null) !== null`). If your callback can legitimately return `null`, it will be recomputed on every call — wrap such values in an array or sentinel object.
- `Request::cache($prefix)` memoizes the `RequestCache` instance on the request: the prefix argument only takes effect on the *first* call. Use `->withPrefix()` to get a differently-scoped instance afterwards.
- Tag invalidation is adapter-dependent and not guaranteed to be perfectly atomic across tags.
- The default file adapter roots itself at `getcwd() . '/storage/cache'` — in long-running or CLI contexts, pass an explicit path to `FileCacheAdapter` instead of relying on the working directory.
:::

## Related

- [Requests](/essentials/requests) — the `Request` object exposing `cache()`
- [Cache API reference](/api/cache)
- [CLI Commands](/features/cli) — `cache:clear` and friends
- [Rate limiting cookbook](/cookbook/rate-limiting) — counters in practice
