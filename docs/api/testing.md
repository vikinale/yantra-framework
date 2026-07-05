# Testing

`System\Testing\Http\TestClient` · `System\Testing\Http\TestResponse`

The HTTP testing utilities let you dispatch requests through the real `Kernel` in-process and assert on the resulting response — no web server required. `TestClient` builds and sends requests (with fluent header/session/auth/CSRF setup); each send returns a `TestResponse` carrying the response for chainable assertions. For a full walkthrough see the [HTTP tests guide](/testing/http-tests).

```php
$client = new TestClient($kernel);

$client->actingAs($user->id, ['admin'])
    ->get('/dashboard')
    ->assertStatus(200)
    ->assertJsonPath('user.name', 'Test User');
```

## TestClient

Constructed with a `System\Core\Kernel`. The `withHeader`/`withSession`/`actingAs`/`withCsrf` methods return `$this` for chaining; the verb methods dispatch and return a `TestResponse`.

### Request setup

| Method | Returns | Description |
| --- | --- | --- |
| `withHeader(string $key, string $value)` | `self` | Add a default header applied to every subsequent request. |
| `withSession(array $data)` | `self` | Merge key/value pairs into the current session via `SessionStore::set()`. |
| `actingAs(int\|string $userId, array $roles = [], string $name = 'Test User', string $email = 'test@example.com')` | `self` | Establish an authenticated session via `SessionStore::loginSuccess()`. |
| `withCsrf()` | `self` | Mint a CSRF token and send it as the `X-CSRF-TOKEN` header on subsequent requests. |

### Dispatching requests

| Method | Returns | Description |
| --- | --- | --- |
| `get(string $uri, array $query = [])` | `TestResponse` | GET request; `$query` is appended as a query string. |
| `post(string $uri, array $data = [])` | `TestResponse` | POST request with body data. |
| `put(string $uri, array $data = [])` | `TestResponse` | PUT request with body data. |
| `delete(string $uri, array $data = [])` | `TestResponse` | DELETE request with body data. |
| `json(string $method, string $uri, array $data = [])` | `TestResponse` | Dispatch with `Content-Type: application/json` and `Accept: application/json` headers. |
| `request(string $method, string $uri, array $data = [], array $headers = [])` | `TestResponse` | Low-level dispatch used by all the verbs above. |

## TestResponse

Wraps the `System\Http\Response` returned by the kernel. Assertion methods throw a `RuntimeException` (with a descriptive message) on failure and return `$this` on success, so they chain. Accessors expose the raw response data.

### Assertions

| Method | Returns | Description |
| --- | --- | --- |
| `assertStatus(int $status)` | `self` | Fail unless the status code equals `$status`. |
| `assertHeader(string $name, mixed $value)` | `self` | Fail unless the header line for `$name` exactly equals `(string) $value`. |
| `assertJson(array $subset)` | `self` | Fail unless the decoded JSON body contains `$subset` (recursive subset match). |
| `assertJsonPath(string $path, mixed $expected)` | `self` | Fail unless the value at dot-notation `$path` in the JSON body strictly equals `$expected`. |
| `assertRedirect(string $uri = null)` | `self` | Fail unless the status is a redirect (301/302/303/307/308); if `$uri` is given, also assert the `Location` header. |

### Accessors

| Method | Returns | Description |
| --- | --- | --- |
| `status()` | `int` | The response status code. |
| `body()` | `string` | The raw response body (rewinds the stream). |
| `json()` | `array` | The decoded JSON body. Throws `RuntimeException` if the body is not valid JSON. |
| `headers()` | `array` | All response headers. |

## Examples

### Authenticated JSON request

```php
$response = (new TestClient($kernel))
    ->actingAs(1, ['user'])
    ->withHeader('Accept', 'application/json')
    ->get('/api/me');

$response->assertStatus(200)
    ->assertJson(['id' => 1])
    ->assertJsonPath('roles.0', 'user');
```

### POST with CSRF

```php
(new TestClient($kernel))
    ->withCsrf()
    ->post('/profile', ['name' => 'Asha'])
    ->assertRedirect('/profile');
```

### Reading raw response data

```php
$res = $client->get('/report.csv');
$res->assertStatus(200)
    ->assertHeader('Content-Type', 'text/csv');

$rows = str_getcsv($res->body());
```

::: warning Gotchas
- **The assertion set is exactly the five listed above.** There is **no `assertHasHeader`, `assertHeaderContains`, or `assertBodyContains`.** Use `assertHeader()` for an exact header match, or read `body()` / `json()` and assert with your own test framework.
- **`assertHeader()` and `assertJsonPath()` are strict, exact comparisons** — `assertHeader` compares the full header line as a string; `assertJsonPath` uses `!==`. There is no substring or loose matching.
- **`assertJson()` is a recursive *subset* match**, whereas `assertJsonPath()` is exact for a single path — pick the one that fits.
- **`actingAs()` argument order is `($userId, $roles, $name, $email)`** — note this differs from `SessionStore::loginSuccess($id, $email, $roles, $name)`.
- **`json()` throws** on a non-JSON body; guard with `assertStatus()` / content-type checks first if a response might be HTML.
:::

## Related

- [HTTP tests guide](/testing/http-tests)
- [Testing getting started](/testing/getting-started)
- [SessionStore API Reference](/api/session-store)
- [Security API Reference](/api/security)
