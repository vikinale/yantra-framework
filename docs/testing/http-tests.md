# HTTP Tests

Yantra's `TestClient` dispatches requests straight through the kernel — no web server, no cURL — and returns a `TestResponse` with chainable assertions. Inside a Yantra `TestCase` you get a pre-wired client from the test context via `$ctx->http()`; every request runs through your real routes, middleware, and controllers against the sandboxed database and session.

```php
protected function act(TestContext $ctx, array $row): mixed
{
    return $ctx->http()->get('/users/1');
}

protected function assert(TestContext $ctx, array $row, mixed $result): void
{
    $result->assertStatus(200)->assertJson(['id' => 1]);
}
```

## The TestClient

`System\Testing\Http\TestClient` builds a PSR-7 request, wraps it in `System\Http\Request`, and hands it to the kernel's `handle()` — the same path a real request takes.

### Configuring the request

All configuration methods return `$this`, so they chain:

```php
$ctx->http()
    ->withHeader('Accept-Language', 'de')
    ->withSession(['theme' => 'dark'])
    ->actingAs(42, ['admin'])
    ->withCsrf()
    ->post('/admin/settings', ['theme' => 'dark']);
```

| Method | Signature | What it does |
|---|---|---|
| `withHeader` | `withHeader(string $key, string $value): self` | Adds a default header sent with every subsequent request from this client. |
| `withSession` | `withSession(array $data): self` | Merges key/value pairs into the (sandboxed) session via `SessionStore::set()`. |
| `actingAs` | `actingAs(int\|string $userId, array $roles = [], string $name = 'Test User', string $email = 'test@example.com'): self` | Marks the session as authenticated for the given user id, roles, and profile — auth middleware sees a logged-in user. |
| `withCsrf` | `withCsrf(): self` | Generates a valid token via `Csrf::token()` (stored in the session) and attaches it as the `X-CSRF-TOKEN` header, so CSRF middleware passes. |

Because `withSession()` and `actingAs()` write into the session sandbox, their effects are wiped automatically at the end of each test case.

### Sending requests

Every request method returns a `TestResponse`.

| Method | Signature |
|---|---|
| `get` | `get(string $uri, array $query = []): TestResponse` — `$query` is appended to the URI as a query string. |
| `post` | `post(string $uri, array $data = []): TestResponse` |
| `put` | `put(string $uri, array $data = []): TestResponse` |
| `delete` | `delete(string $uri, array $data = []): TestResponse` |
| `json` | `json(string $method, string $uri, array $data = []): TestResponse` — sends `Content-Type: application/json` and `Accept: application/json`. |
| `request` | `request(string $method, string $uri, array $data = [], array $headers = []): TestResponse` — the low-level escape hatch behind all of the above. |

```php
$ctx->http()->get('/search', ['q' => 'yantra', 'page' => 2]);   // GET /search?q=yantra&page=2
$ctx->http()->json('POST', '/api/posts', ['title' => 'Hello']); // JSON request
$ctx->http()->request('PATCH', '/api/posts/1', ['title' => 'Hi'], ['X-Trace' => 'abc']);
```

## TestResponse Assertions

`System\Testing\Http\TestResponse` wraps the final `System\Http\Response`. Assertions throw a `RuntimeException` with a descriptive message on failure (PHPUnit reports it as an error for the case) and return `$this` on success, so they chain.

This is the complete list of assertion methods:

| Method | Signature | Checks |
|---|---|---|
| `assertStatus` | `assertStatus(int $status): self` | Exact status code; failure message includes the first 500 bytes of the body. |
| `assertHeader` | `assertHeader(string $name, mixed $value): self` | Header line equals the given value exactly. |
| `assertJson` | `assertJson(array $subset): self` | The decoded JSON body contains the given subset (recursively — nested arrays are matched key by key). |
| `assertJsonPath` | `assertJsonPath(string $path, mixed $expected): self` | Dot-notation path into the JSON body strictly equals the expected value (`'data.user.id'`). |
| `assertRedirect` | `assertRedirect(string $uri = null): self` | Status is one of 301/302/303/307/308; if `$uri` is given, the `Location` header must equal it. |

### Inspecting the response directly

For anything beyond the built-in assertions, pull the raw pieces out and use PHPUnit's own assertions:

| Method | Returns |
|---|---|
| `status(): int` | Status code. |
| `body(): string` | Full body (the stream is rewound for you). |
| `json(): array` | Decoded JSON body; throws on invalid JSON. |
| `headers(): array` | All headers as `name => string[]`. |

```php
$res = $ctx->http()->get('/about');
$this->assertStringContainsString('About Us', $res->body());
$this->assertArrayHasKey('Content-Type', $res->headers());
```

## Complete Example: Testing an API Endpoint

An authenticated, CSRF-protected endpoint that creates a post, exercised with the full Yantra `TestCase` pattern:

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use System\Testing\Contracts\TestCase;
use System\Testing\Data\DataSet;
use System\Testing\Runtime\TestContext;
use System\Testing\Http\TestResponse;

final class CreatePostApiTest extends TestCase
{
    public static function suiteName(): string
    {
        return 'POST /api/posts';
    }

    public static function dataset(): array
    {
        return [
            DataSet::rows([
                [
                    'case_id' => 'C001', 'title' => 'editor can create a post',
                    'user_id' => 7, 'roles' => ['editor'],
                    'payload' => ['title' => 'Hello Yantra', 'body' => 'First post'],
                    'status'  => 201,
                ],
                [
                    'case_id' => 'C002', 'title' => 'missing title is rejected',
                    'user_id' => 7, 'roles' => ['editor'],
                    'payload' => ['body' => 'No title here'],
                    'status'  => 422,
                ],
            ]),
        ];
    }

    protected function act(TestContext $ctx, array $row): mixed
    {
        return $ctx->http()
            ->actingAs($row['user_id'], $row['roles'])   // authenticated session
            ->withCsrf()                                  // valid X-CSRF-TOKEN header
            ->json('POST', '/api/posts', $row['payload']);
    }

    protected function assert(TestContext $ctx, array $row, mixed $result): void
    {
        /** @var TestResponse $result */
        $result->assertStatus($row['status']);

        if ($row['status'] === 201) {
            $result
                ->assertJson(['title' => $row['payload']['title']])
                ->assertJsonPath('author.id', $row['user_id']);
        }
    }
}
```

Any rows the controller inserts are rolled back by the database sandbox, and the authenticated session created by `actingAs()` is cleared — every case starts clean. See [Sandboxes & Fakes](/testing/sandboxes).

::: warning Gotchas
- `withHeader()` registers a **default** header on the client — it applies to every request made afterwards from that client instance, not just the next one.
- `withSession()` and `actingAs()` write to the session **immediately**, not when the request is sent. Call them before the request in the same case; the sandbox resets the session between cases.
- `assertJson()` matches a **subset**: extra keys in the response are fine. Use `assertJsonPath()` when you need a strict (`!==`) comparison of one value, including its type.
- `assertJsonPath()` compares strictly — `assertJsonPath('id', 1)` fails if the API returns `"1"` as a string.
- `withCsrf()` sends the token via the `X-CSRF-TOKEN` header. If your form-based route only reads a `_token` POST field, include the token in the request data instead.
- There is no `assertBodyContains()` — use `body()` with PHPUnit's `assertStringContainsString()` as shown above.
:::

## Related

- [Testing: Getting Started](/testing/getting-started)
- [Sandboxes & Fakes](/testing/sandboxes)
- [Requests](/essentials/requests)
- [Responses](/essentials/responses)
- [CSRF Protection](/security/csrf)
- [Authentication](/security/authentication)
- [API: Testing](/api/testing)
