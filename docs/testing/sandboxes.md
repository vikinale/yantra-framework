# Sandboxes & Fakes

Every Yantra test case runs inside an isolation toolkit: a database transaction that is rolled back, a throwaway temp directory, in-memory cache and session adapters, and a freezable clock. The Yantra `TestCase` wires all of them up before each dataset row and tears them down afterwards — you rarely construct them yourself, you just use them through the properties (`$this->db`, `$this->fs`, …) or the `TestContext` accessors (`$ctx->db()`, `$ctx->fs()`, `$ctx->cache()`, `$ctx->session()`, `$ctx->clock()`).

```php
protected function arrange(TestContext $ctx, array $row): void
{
    $ctx->clock()->freeze('2026-01-01 09:00:00');          // deterministic time
    file_put_contents($ctx->fs()->path('input.csv'), "a,b\n1,2\n"); // temp file
    // any DB writes here are rolled back after the case
}
```

## DbSandbox: Transaction-per-Test

`System\Testing\Sandbox\DbSandbox` wraps each test case in a database transaction on the framework's active connection (`ConnectionResolver::get()`). Everything written during the case — by `arrange()`, by controllers hit through the `TestClient`, by anything — disappears on rollback, so cases never leak rows into each other.

| Method | Behavior |
|---|---|
| `begin(): void` | Connects and starts a transaction. No-ops if this sandbox already began one, or if the connection is already inside a transaction. |
| `rollback(): void` | Rolls the transaction back; rollback errors are swallowed. |

The `TestCase` calls `begin()` before each case and `rollback()` in a `finally` block afterwards — you only touch these methods when writing a custom runner.

```php
protected function arrange(TestContext $ctx, array $row): void
{
    // Inserted inside the sandbox transaction — gone after the case.
    \System\Database\Database::getInstance()->query(
        "INSERT INTO users (email) VALUES (?)",
        ['temp@example.com']
    );
}
```

## FsSandbox: Throwaway Filesystem

`System\Testing\Sandbox\FsSandbox` gives each test case a unique directory under the system temp dir (`yantra_test_<uniqid>`), created before the case and deleted recursively after it.

| Method | Behavior |
|---|---|
| `init(): void` | Creates the sandbox root directory. |
| `path(string $relative = ''): string` | Absolute path inside the sandbox; with no argument, the root itself. |
| `cleanup(): void` | Recursively deletes the sandbox directory. |

```php
protected function act(TestContext $ctx, array $row): mixed
{
    $csv = $ctx->fs()->path('import/users.csv');
    mkdir(dirname($csv), 0777, true);
    file_put_contents($csv, "email\na@b.com\n");

    return (new \App\Services\UserImporter())->import($csv);
}
```

Point any code that writes files (uploads, exports, report output) at `$ctx->fs()->path(...)` and nothing survives the test.

## CacheSandbox: In-Memory Cache

`System\Testing\Sandbox\CacheSandbox` swaps the framework's cache backend for an `ArrayCacheAdapter` — a plain PHP array — via `Cache::setAdapter()`. No files, no Redis, and the store is flushed between cases.

| Method | Behavior |
|---|---|
| `init(): void` | Installs the `ArrayCacheAdapter` as the global cache adapter. |
| `reset(): void` | Flushes all stored entries. |

`ArrayCacheAdapter` implements the framework's cache adapter interface: `put($key, $value, $ttl = 0)`, `get($key, $default = null)` (TTL-aware — expired entries return the default), `has($key)`, `forget($key)`, `increment($key, $amount = 1)`, `decrement($key, $amount = 1)`, `flush()`, `putWithTags(...)`, `invalidateTag(...)`.

```php
protected function act(TestContext $ctx, array $row): mixed
{
    \System\Utilities\Cache::put('answer', 42, 60);
    return \System\Utilities\Cache::get('answer');
}

protected function assert(TestContext $ctx, array $row, mixed $result): void
{
    $this->assertSame(42, $result);
}
```

## SessionSandbox: In-Memory Session

`System\Testing\Sandbox\SessionSandbox` replaces PHP's native session with an `ArraySessionAdapter`, resetting `SessionStore`'s internal singleton state so the swap works even after the app has booted. `TestClient::withSession()` and `actingAs()` write into this adapter.

| Method | Behavior |
|---|---|
| `init(): void` | Resets `SessionStore` and installs the array adapter. |
| `reset(): void` | Clears all session data. |

`ArraySessionAdapter` covers the session adapter contract: `start()`, `get($key, $default = null)`, `set($key, $value)`, `has($key)`, `remove($key)`, `all()`, `clear()`, `regenerate()`, `destroy()`.

```php
protected function arrange(TestContext $ctx, array $row): void
{
    \System\Session\SessionStore::set('cart', ['sku-1' => 2]);
}

protected function act(TestContext $ctx, array $row): mixed
{
    return $ctx->http()->get('/cart');   // controller reads the same in-memory session
}
```

See [Session](/essentials/session) for the `SessionStore` API itself.

## ClockFake: Freezable Time

`System\Testing\Sandbox\ClockFake` is a tiny clock you can pin to a fixed instant for deterministic time-based assertions. The `TestCase` calls `reset()` before every case, so a freeze never bleeds into the next row.

| Method | Behavior |
|---|---|
| `freeze(string $datetimeString): void` | Pin the clock to a point in time, e.g. `'2023-01-01 12:00:00'`. |
| `reset(): void` | Return to real system time. |
| `now(): DateTimeImmutable` | The frozen instant, or the real current time. |
| `timestamp(): int` | Unix timestamp of `now()`. |

```php
protected function arrange(TestContext $ctx, array $row): void
{
    $ctx->clock()->freeze('2026-12-31 23:59:00');
}

protected function act(TestContext $ctx, array $row): mixed
{
    // The service must accept the clock (or its now()/timestamp() value) —
    // ClockFake does NOT change what PHP's time() returns.
    $service = new \App\Services\SubscriptionChecker($ctx->clock()->now());
    return $service->isExpired($row['expires_at']);
}
```

## FixtureManager and Seeders

`System\Testing\Fixtures\FixtureManager` loads fixture classes on demand and remembers which ones ran, so the same fixture class is loaded at most once per manager instance.

| Method | Behavior |
|---|---|
| `load(string $fixtureClass): void` | Instantiates the class (throws if it doesn't exist), calls its `load()` method if one is defined, and memoizes it. |
| `clear(): void` | Forgets which fixtures were loaded, so they can be loaded again. |

A fixture is any class with a `load()` method:

```php
final class UsersFixture
{
    public function load(): void
    {
        \System\Database\Database::getInstance()->query(
            "INSERT INTO users (email) VALUES (?)",
            ['fixture@example.com']
        );
    }
}

protected function arrange(TestContext $ctx, array $row): void
{
    $fixtures = new \System\Testing\Fixtures\FixtureManager();
    $fixtures->load(UsersFixture::class);   // rolled back by DbSandbox afterwards
}
```

For seed data shared with the application, extend the abstract `System\Testing\Fixtures\Seeders` class instead — implement `run(): void` and use the protected `db()` helper (returns `Database::getInstance()`). See [Seeders](/database/seeders) for the application-level seeding workflow.

::: warning Gotchas
- **DbSandbox relies on the rollback holding.** Application code that calls `commit()` itself, or DDL statements on MySQL (which auto-commit), escapes the transaction and persists across cases. On the default in-memory SQLite test database this is rarely an issue.
- **ClockFake does not patch `time()`, `date()`, or `new DateTime()`.** Freezing only affects code that reads time from the clock (`$ctx->clock()->now()` / `timestamp()`). Pass the clock (or its value) into the code under test.
- `ArrayCacheAdapter::has()` returns `false` for keys that store `null` (it's implemented as `get($key) !== null`), and its tag support is a stub: `putWithTags()` stores the value but ignores tags, and `invalidateTag()` is a no-op. Don't test tag-invalidation behavior against the array adapter.
- `FixtureManager` memoizes per **instance** — a fresh manager in each case reloads fixtures. That's usually what you want, since DbSandbox rolled back the previous case's rows anyway.
- Each `FsSandbox` instance gets its own unique directory, so absolute paths cached between cases point at deleted directories — always re-derive paths via `$ctx->fs()->path()`.
:::

## Related

- [Testing: Getting Started](/testing/getting-started)
- [HTTP Tests](/testing/http-tests)
- [Cache](/features/cache)
- [Session](/essentials/session)
- [Database: Seeders](/database/seeders)
- [Database: Migrations](/database/migrations)
- [API: Testing](/api/testing)
