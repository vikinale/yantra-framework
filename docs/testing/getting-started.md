# Testing: Getting Started

Yantra ships a data-driven testing layer on top of PHPUnit. Instead of writing one method per scenario, you extend Yantra's `TestCase`, declare a `DataSet` of rows, and implement the classic Arrange–Act–Assert steps once — the framework runs every row through them with a fresh sandbox (database transaction, temp filesystem, in-memory cache and session, fake clock) that is torn down automatically after each case.

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use System\Testing\Contracts\TestCase;
use System\Testing\Data\DataSet;
use System\Testing\Runtime\TestContext;

final class HealthEndpointTest extends TestCase
{
    public static function suiteName(): string
    {
        return 'Health endpoint';
    }

    public static function dataset(): array
    {
        return [
            DataSet::rows([
                ['case_id' => 'C001', 'title' => 'root responds', 'uri' => '/', 'status' => 200],
            ]),
        ];
    }

    protected function act(TestContext $ctx, array $row): mixed
    {
        return $ctx->http()->get($row['uri']);
    }

    protected function assert(TestContext $ctx, array $row, mixed $result): void
    {
        $result->assertStatus($row['status']);
    }
}
```

## Installation and Setup

The framework's dev dependencies already include PHPUnit and Mockery:

```json
"require-dev": {
    "phpunit/phpunit": "11.5.50",
    "mockery/mockery": "^1.6"
},
"scripts": {
    "test": "phpunit"
}
```

Run the whole suite with either:

```bash
composer test
# or
vendor/bin/phpunit
```

### phpunit.xml

The framework's `phpunit.xml` forces the `testing` environment and points the database at an in-memory SQLite instance, so tests never touch your real data:

```xml
<phpunit
    bootstrap="tests/bootstrap.php"
    colors="true"
    cacheDirectory=".phpunit.cache"
    failOnRisky="true"
    failOnWarning="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>Tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>Tests/Feature</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_DRIVER" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
    </php>
</phpunit>
```

Test classes autoload from the `Tests\` namespace (mapped to `Tests/` in `autoload-dev`).

## The Yantra TestCase

`System\Testing\Contracts\TestCase` extends `PHPUnit\Framework\TestCase` and adapts Yantra's data-driven structure to PHPUnit. Every subclass declares:

| Member | Required | Purpose |
|---|---|---|
| `static suiteName(): string` | Yes | Human-readable name of the suite. |
| `static dataset(): array` | Yes | Returns an array of `DataSet` objects — one row per test case. |
| `static kernel(): string` | No | Kernel class used to boot the app. Defaults to `System\Testing\Kernel\AppTestKernel`; use `FrameworkTestKernel` for framework-internal tests. |
| `arrange(TestContext $ctx, array $row): void` | No | Prepare state (seed rows, freeze the clock, prime the session). |
| `act(TestContext $ctx, array $row): mixed` | Yes | Execute the behavior under test; the return value flows to `assert()`. |
| `assert(TestContext $ctx, array $row, mixed $result): void` | Yes | Verify the outcome. |
| `beforeEach(TestContext $ctx, array $row)` / `afterEach(TestContext $ctx, array $row)` | No | Run around every row. `afterEach` runs even when the case fails. |
| `beforeAll(TestContext $ctx)` / `afterAll(TestContext $ctx)` | No | Class-level hooks (see Gotchas below). |

### Per-case lifecycle

For each dataset row the runner executes, in order:

1. Skips the case if the row has a non-empty `skip` value (`markTestSkipped`).
2. Initializes the filesystem sandbox and boots a `TestContext`.
3. Begins a database transaction, installs the in-memory session and cache adapters, and resets the fake clock.
4. `beforeEach()` → `arrange()` → `act()` → `assert()`.
5. In a `finally` block: `afterEach()`, then rolls back the transaction and resets session, cache, and the temp filesystem.

### Sandbox properties and the TestContext

The base class exposes the sandboxes as properties, and the same objects are handed to every step through the `TestContext`:

| Property | Context accessor | Type |
|---|---|---|
| `$this->db` | `$ctx->db()` | `DbSandbox` — transaction-per-test rollback |
| `$this->fs` | `$ctx->fs()` | `FsSandbox` — unique temp directory |
| `$this->cache` | `$ctx->cache()` | `CacheSandbox` — in-memory array cache |
| `$this->session` | `$ctx->session()` | `SessionSandbox` — in-memory array session |
| `$this->clock` | `$ctx->clock()` | `ClockFake` — freezable clock |
| `$this->app` | `$ctx->container()` | `Application` — the booted app |
| — | `$ctx->http()` | `TestClient` — in-process HTTP client |
| — | `$ctx->meta($key)` | Arbitrary metadata attached to the context |

See [Sandboxes & Fakes](/testing/sandboxes) for what each one isolates, and [HTTP Tests](/testing/http-tests) for the client.

## DataSet: Parameterized Rows

`System\Testing\Data\DataSet` wraps an array of associative rows. Each row is one test case; `case_id` and `title` are used to label the case in PHPUnit output, and `skip` (non-empty) skips it with the given reason.

```php
use System\Testing\Data\DataSet;

public static function dataset(): array
{
    return [
        DataSet::rows([
            ['case_id' => 'C001', 'title' => 'valid email', 'email' => 'a@b.com', 'ok' => true],
            ['case_id' => 'C002', 'title' => 'bad email',   'email' => 'nope',    'ok' => false],
            ['case_id' => 'C003', 'title' => 'todo',        'skip'  => 'awaiting fix #42'],
        ]),
    ];
}
```

Factory methods:

- `DataSet::rows(array $rows)` / `DataSet::create(array $rows)` — build from inline arrays.
- `DataSet::csv(string $path)` — load rows from a CSV file (`CsvDataSet`). The first line is the header. Dotted headers expand into nested arrays (`input.email` → `$row['input']['email']`), `true`/`false`/`null`/numeric strings are cast, and missing `case_id`/`title`/`skip` columns get sensible defaults (`C001`, `C002`, …).

```php
DataSet::csv(__DIR__ . '/fixtures/login_cases.csv');
```

## A Complete Example

A feature test that hits a login route with several credential combinations:

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use System\Testing\Contracts\TestCase;
use System\Testing\Data\DataSet;
use System\Testing\Runtime\TestContext;
use System\Testing\Http\TestResponse;

final class LoginTest extends TestCase
{
    public static function suiteName(): string
    {
        return 'Login flow';
    }

    public static function dataset(): array
    {
        return [
            DataSet::rows([
                [
                    'case_id' => 'C001', 'title' => 'valid credentials redirect',
                    'email' => 'admin@example.com', 'password' => 'secret',
                    'status' => 302,
                ],
                [
                    'case_id' => 'C002', 'title' => 'wrong password rejected',
                    'email' => 'admin@example.com', 'password' => 'wrong',
                    'status' => 422,
                ],
            ]),
        ];
    }

    protected function arrange(TestContext $ctx, array $row): void
    {
        // Runs inside the sandbox transaction — rolled back after the case.
        \System\Database\Database::getInstance()->query(
            "INSERT INTO users (email, password) VALUES (?, ?)",
            ['admin@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
    }

    protected function act(TestContext $ctx, array $row): mixed
    {
        return $ctx->http()->withCsrf()->post('/login', [
            'email'    => $row['email'],
            'password' => $row['password'],
        ]);
    }

    protected function assert(TestContext $ctx, array $row, mixed $result): void
    {
        /** @var TestResponse $result */
        $result->assertStatus($row['status']);
    }
}
```

Mockery works exactly as it does in any PHPUnit project — mock a collaborator in `arrange()` and bind it into the container from `$ctx->container()`.

## Scaffolding Commands

### make:test

Generates a plain PHPUnit test skeleton in `tests/Unit` or `tests/Feature`. Run without arguments for interactive mode.

```bash
php yantra make:test ContactModelTest
php yantra make:test ContactForm --type=feature
php yantra make:test ContactModelTest --db            # SQLite in-memory boilerplate
php yantra make:test ContactModelTest --db=mysql      # framework Database::getInstance()
php yantra make:test MigratorTest --db=migrate        # SQLite + run migration fixtures
php yantra make:test RouterTest --type=unit --db=mysql --force
```

Options: `--type=unit|feature` (default `unit`), `--db[=sqlite|mysql|migrate]` (bare `--db` means `sqlite`), `--force` to overwrite an existing file. A `Test` suffix is appended automatically if missing.

### make:model:test

A shortcut around `make:test` for model tests — appends `Model` and `Test` suffixes for you:

```bash
php yantra make:model:test ContactModel
php yantra make:model:test Contact --db
php yantra make:model:test Contact --db=migrate
php yantra make:model:test ContactModel --type=feature --force
```

Both commands generate self-contained PHPUnit 11 tests using attributes (`#[Test]`); flesh out the generated skeleton, or convert it to the Yantra `TestCase` style when you want datasets and sandboxes.

::: warning Gotchas
- **`beforeAll`/`afterAll` are not currently invoked** by the PHPUnit bridge — PHPUnit's class-level `setUpBeforeClass` is static and cannot receive a `TestContext`. Put per-case setup in `arrange()` or `beforeEach()` instead.
- Everything in `arrange()`/`act()` runs inside a single database transaction that is **rolled back** after the case. Code that commits explicitly (or DDL on MySQL, which auto-commits) escapes the rollback — see [Sandboxes & Fakes](/testing/sandboxes).
- The scaffolding commands emit **plain PHPUnit** tests, not Yantra `TestCase` subclasses — they're a starting point, not the data-driven pattern.
- The fake clock is only a value object; it does **not** patch PHP's `time()`/`date()`. App code must read time from the clock for `freeze()` to matter.
:::

## Related

- [HTTP Tests](/testing/http-tests)
- [Sandboxes & Fakes](/testing/sandboxes)
- [Tutorial: Testing](/tutorial/07-testing)
- [Database: Getting Started](/database/getting-started)
- [CLI](/features/cli)
- [API: Testing](/api/testing)
