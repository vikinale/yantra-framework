# 7. Testing

In this final step you'll write tests for the blog using Yantra's testing toolkit: a data-driven `TestCase` with the Arrange–Act–Assert lifecycle, the in-process `TestClient` (`get`/`post`), and `TestResponse` assertions (`assertStatus`, `assertJson`). Each test runs against a sandboxed database transaction that's rolled back automatically, so tests never touch your real data.

::: tip You'll need the whole tutorial first
These tests exercise the routes, controllers, model, and API from steps [1](/tutorial/01-setup)–[6](/tutorial/06-api). The `posts` table and `Post` model in particular must exist. Tests run against an in-memory SQLite database (configured in `phpunit.xml`), migrated fresh per run.
:::

## How Yantra tests are shaped

Instead of one method per scenario, you extend `System\Testing\Contracts\TestCase`, declare a `DataSet` of rows, and implement the three lifecycle steps once — the runner feeds every row through them:

| Member | Required | Purpose |
|---|---|---|
| `static suiteName(): string` | Yes | Human-readable suite name. |
| `static dataset(): array` | Yes | Array of `DataSet` objects — one row per case. |
| `arrange(TestContext $ctx, array $row): void` | No | Set up state (seed rows, prime the session). |
| `act(TestContext $ctx, array $row): mixed` | Yes | Do the thing under test; the return value flows to `assert()`. |
| `assert(TestContext $ctx, array $row, mixed $result): void` | Yes | Verify the outcome. |

For each row the runner begins a DB transaction, installs sandboxed session/cache/clock, runs `beforeEach` → `arrange` → `act` → `assert`, then rolls everything back. `$ctx->http()` hands you a `TestClient` wired to your real routes and middleware.

Run the suite with:

```bash
composer test
# or
vendor/bin/phpunit
```

## A feature test: listing posts

Create `Tests/Feature/PostIndexTest.php`. It seeds a post in `arrange()`, requests `/posts`, and asserts a 200 with the title in the body:

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use System\Testing\Contracts\TestCase;
use System\Testing\Data\DataSet;
use System\Testing\Runtime\TestContext;
use System\Testing\Http\TestResponse;
use App\Models\Post;

final class PostIndexTest extends TestCase
{
    public static function suiteName(): string
    {
        return 'GET /posts';
    }

    public static function dataset(): array
    {
        return [
            DataSet::rows([
                [
                    'case_id' => 'C001',
                    'title'   => 'lists a published post',
                    'seed'    => 'Hello from tests',
                    'status'  => 200,
                ],
            ]),
        ];
    }

    protected function arrange(TestContext $ctx, array $row): void
    {
        // Runs inside the sandbox transaction — rolled back after the case.
        (new Post)->create([
            'title'     => $row['seed'],
            'slug'      => 'hello-from-tests',
            'body'      => 'Body text.',
            'published' => true,
        ]);
    }

    protected function act(TestContext $ctx, array $row): mixed
    {
        return $ctx->http()->get('/posts');
    }

    protected function assert(TestContext $ctx, array $row, mixed $result): void
    {
        /** @var TestResponse $result */
        $result->assertStatus($row['status']);

        // For anything beyond the built-in assertions, inspect the body directly.
        $this->assertStringContainsString($row['seed'], $result->body());
    }
}
```

`assertStatus()` checks the exact code (its failure message includes the first 500 bytes of the body — handy when a 500 sneaks in). There's no `assertBodyContains()`; use `body()` with PHPUnit's `assertStringContainsString()`.

## A JSON API test with multiple cases

Create `Tests/Feature/ApiPostTest.php`. One dataset drives several scenarios against `/api/posts` and `/api/token`:

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use System\Testing\Contracts\TestCase;
use System\Testing\Data\DataSet;
use System\Testing\Runtime\TestContext;
use System\Testing\Http\TestResponse;
use App\Models\Post;

final class ApiPostTest extends TestCase
{
    public static function suiteName(): string
    {
        return 'JSON API /api/posts';
    }

    public static function dataset(): array
    {
        return [
            DataSet::rows([
                [
                    'case_id' => 'C001',
                    'title'   => 'public index returns success envelope',
                    'status'  => 200,
                ],
                [
                    'case_id' => 'C002',
                    'title'   => 'creating without a token is rejected',
                    'status'  => 401,
                ],
            ]),
        ];
    }

    protected function arrange(TestContext $ctx, array $row): void
    {
        (new Post)->create([
            'title'     => 'Seed',
            'slug'      => 'seed',
            'body'      => 'Seed body.',
            'published' => true,
        ]);
    }

    protected function act(TestContext $ctx, array $row): mixed
    {
        if ($row['case_id'] === 'C002') {
            // No Authorization header → the jwt middleware should block it.
            return $ctx->http()->json('POST', '/api/posts', [
                'title' => 'Nope',
                'slug'  => 'nope',
                'body'  => 'x',
            ]);
        }

        return $ctx->http()->get('/api/posts');
    }

    protected function assert(TestContext $ctx, array $row, mixed $result): void
    {
        /** @var TestResponse $result */
        $result->assertStatus($row['status']);

        if ($row['case_id'] === 'C001') {
            // assertJson matches a subset of the decoded body.
            $result->assertJson(['status' => 'success']);
        }
    }
}
```

`assertJson([...])` checks that the decoded body **contains** the given subset (extra keys are fine, nested arrays match key by key). For a strict, type-sensitive comparison of one value use `assertJsonPath('data.0.title', 'Seed')`.

## Testing an authenticated write

The `TestClient` can fake an authenticated session and a valid CSRF token, so you can exercise the web write routes from step 5 without a real login round-trip:

```php
protected function act(TestContext $ctx, array $row): mixed
{
    return $ctx->http()
        ->actingAs(1, [])          // session auth for user id 1 (auth middleware sees them in)
        ->withCsrf()               // valid _csrf token via X-CSRF-TOKEN header
        ->post('/posts', [
            'title' => 'Written in a test',
            'slug'  => 'written-in-a-test',
            'body'  => 'Body.',
        ]);
}

protected function assert(TestContext $ctx, array $row, mixed $result): void
{
    /** @var TestResponse $result */
    $result->assertRedirect('/posts');   // redirectAfterPost → 303 to /posts
}
```

`actingAs($userId, $roles)` and `withCsrf()` write into the sandboxed session immediately (before the request) and are wiped between cases.

::: warning Gotchas
- Everything in `arrange()`/`act()` runs inside a **single DB transaction that is rolled back** after the case. Code that commits explicitly — or DDL on MySQL, which auto-commits — escapes the rollback. The SQLite test DB avoids the DDL trap.
- `withCsrf()` sends the token as the `X-CSRF-TOKEN` header. Our web forms read a `_csrf` **field**; the `sec.csrf` middleware checks the header first, so `withCsrf()` works when that middleware is active. If a route only reads the `_csrf` POST field, include the token in the request data instead.
- The `jwt` middleware from step 6 verifies HS256 against `JWT_SECRET`. To test a *successful* authenticated API write, first `POST /api/token`, read the token from the JSON body, then send it via `->withHeader('Authorization', 'Bearer ' . $token)`.
- `withHeader()` sets a **default** header for every subsequent request from that client instance, not just the next one.
- `assertJson()` matches a subset; `assertJsonPath()` compares strictly (`!==`), so `assertJsonPath('id', 1)` fails if the API returns `"1"` as a string.
- `beforeAll`/`afterAll` are **not** invoked by the PHPUnit bridge — put shared setup in `arrange()`/`beforeEach()`.
:::

## Scaffolding tests

The CLI can generate a skeleton to start from (plain PHPUnit, which you can convert to the `TestCase` style above):

```bash
php vendor/bin/yantra make:test PostIndexTest --type=feature
php vendor/bin/yantra make:test PostModelTest --db        # SQLite in-memory boilerplate
```

## You built a blog

Across seven steps you scaffolded an app, modeled and migrated data, built full CRUD with views, validated input, added session auth with throttling, exposed a JWT-secured JSON API, and covered it all with tests — on a zero-dependency framework. From here, explore the deeper guides below.

← [6. JSON API & JWT](/tutorial/06-api)

## Related

- [Testing: Getting Started](/testing/getting-started)
- [HTTP Tests](/testing/http-tests)
- [Sandboxes & Fakes](/testing/sandboxes)
- [Database: Getting Started](/database/getting-started)
