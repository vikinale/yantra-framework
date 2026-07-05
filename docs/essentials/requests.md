# Requests

Yantra's `System\Http\Request` wraps PHP's superglobals (`$_GET`, `$_POST`, `$_SERVER`, `$_FILES`, `php://input`) behind a single object with a consistent API for reading input, headers, uploaded files, and client information. The framework creates the request for you at the start of every HTTP cycle and injects it into controllers and middleware — you never construct one manually in application code.

```php
use System\Core\BaseController;
use System\Http\Response;

class UserController extends BaseController
{
    public function store(): Response
    {
        $name  = $this->req()->input('name');
        $email = $this->req()->input('email', 'unknown@example.com');

        // ... create the user ...

        return $this->success(['name' => $name, 'email' => $email], 201);
    }
}
```

## Getting the Request

When `Application::run()` boots the framework, it builds a `Request` from the current globals and passes it through the kernel to your controller. There are two ways to reach it:

**Via the `req()` accessor.** Every controller extending `System\Controller` (including `System\Core\BaseController`) holds the request in `$this->request` and exposes a protected `req()` accessor:

```php
public function show(): Response
{
    $request = $this->req();          // same object as $this->request
    $id = $request->input('id');
    // ...
}
```

**Via constructor injection.** The controller factory inspects your constructor and injects the current `Request` (and `Response`) automatically, alongside any other services resolvable from the container:

```php
use System\Http\Request;
use System\Http\Response;

class ReportController extends BaseController
{
    public function __construct(
        Request $request,
        Response $response,
        private ReportService $reports   // resolved from the container
    ) {
        parent::__construct($request, $response);
    }
}
```

A static factory `Request::fromGlobals()` also exists — it simply returns `new Request()` reading from the superglobals. It is mainly useful in tests or bootstrap code; inside the framework lifecycle, use the injected instance so middleware attributes (see below) travel with it.

## Reading Input

### `all()` — the merged input array

```php
$data = $request->all();
```

`all(): array` (an alias of `inputs()`) returns a single merged array built as follows, with later sources overriding earlier ones:

1. Query-string values (`$_GET`)
2. Form body values (`$_POST`), when present
3. If the `Content-Type` contains `application/json`: the decoded JSON body
4. Otherwise, for `PUT`/`PATCH`/`DELETE` requests with a non-multipart body: the URL-encoded raw body, parsed with `parse_str()`

The merged result is computed once and cached on the request instance, so repeated calls are cheap.

### `input()` — one key with dot notation

```php
$name  = $request->input('name');                    // null if missing
$email = $request->input('email', 'n/a');            // with default
$city  = $request->input('address.city');            // nested: ['address' => ['city' => ...]]
```

`input(string $key, $default = null)` looks the key up in the merged `all()` data. Dot notation descends into nested arrays — `'address.city'` reads `$data['address']['city']`. Passing an empty string returns the whole array. `get(string $key, $default = null)` and `inputGet()` are aliases of `input()`.

### `only()` — a whitelist of keys

```php
$credentials = $request->only(['email', 'password']);
$profile     = $request->only(['name', 'bio'], ['bio' => '']);  // per-key defaults
```

`only(array $keys, array $defaults = []): array` returns exactly the requested keys (each resolved through `input()`, so dot notation works). Keys missing from the input appear with their default (or `null`). This is the safest way to feed user input into a model — nothing you didn't ask for gets through.

### Source-specific accessors

When you need to know *where* a value came from, bypass the merge:

```php
// Query string only ($_GET)
$page = $request->getQuery('page', 1);      // getQuery(?string $key = null, $default = null)
$page = $request->param('page', 1);         // identical behavior
$all  = $request->getQuery();               // whole $_GET array when key is null

// getOnly() adds dot notation on top of $_GET
$sort = $request->getOnly('filters.sort', 'asc');

// Form body only ($_POST), with dot notation
$token = $request->post('_csrf');           // post(?string $key = null, $default = null)
$all   = $request->post();                  // whole $_POST array
$deep  = $request->postOnly('user.name');   // post() is an alias of postOnly()
```

### Checking presence

```php
if ($request->has('remember_me')) {
    // key exists (top-level, no dot notation) in the merged input
}
```

## JSON Bodies

Two methods deal specifically with JSON:

```php
// Does the client *want* JSON back? (checks the Accept header)
if ($request->acceptsJson()) {
    return $this->response->json(['ok' => true]);
}

// Read straight from the raw JSON body, ignoring $_GET/$_POST
$total = $request->jsonInput('order.total', 0.0);
```

- `acceptsJson(): bool` returns `true` when the `Accept` header contains `application/json`. It says nothing about the request body — it's about content negotiation for your response.
- `jsonInput(string $key, $default = null)` decodes `php://input` as JSON and resolves `$key` with dot notation. If the body isn't valid JSON, you get `$default` back.

In practice you rarely need `jsonInput()`: when the request's `Content-Type` is `application/json`, the decoded body is already merged into `all()`/`input()`.

## Headers

```php
$type  = $request->header('Content-Type');          // ?string
$auth  = $request->header('Authorization', '');     // with default
$all   = $request->headers();                       // ['Accept' => '...', 'Content-Type' => '...', ...]
$agent = $request->userAgent();                     // shortcut for header('User-Agent')
```

- `header(string $name, $default = null): ?string` is case-insensitive and handles the `Content-Type`/`Content-Length` special cases (which PHP stores without the `HTTP_` prefix). Missing or empty headers return `$default`.
- `headers(): array` returns all request headers as an associative array, using `getallheaders()` when available and reconstructing names from `$_SERVER` otherwise.
- `userAgent(?string $default = null): ?string` is a convenience wrapper for the `User-Agent` header.

## Method Checks

```php
$request->getMethod();   // 'GET', 'POST', ... (uppercased)

$request->isGet();       // bool
$request->isPost();
$request->isPut();
$request->isDelete();

$request->isAjax();      // X-Requested-With: XMLHttpRequest
```

`isAjax()` performs a case-insensitive comparison of the `X-Requested-With` header against `XMLHttpRequest` — the convention used by jQuery and most JS libraries. Controllers also inherit their own `isGet()`/`isPost()`/`isPut()`/`isPatch()`/`isDelete()` shortcuts from the base `Controller` class.

## Client IP

```php
$ip = $request->ip();               // raw REMOTE_ADDR, or null
$ip = $request->trustedProxyIp();   // proxy-aware resolution
```

- `ip(): ?string` returns `$_SERVER['REMOTE_ADDR']` verbatim — the TCP peer that connected to your server. Behind a load balancer or reverse proxy, this is the **proxy's** address, not the visitor's.
- `trustedProxyIp(): ?string` resolves the real client IP behind a proxy — but only when it is safe to do so. It works like this:
  1. If `REMOTE_ADDR` does **not** match a trusted proxy, the `X-Forwarded-For` header is ignored entirely and `REMOTE_ADDR` is returned. This prevents clients from spoofing their IP by sending the header themselves.
  2. If `REMOTE_ADDR` *is* a trusted proxy, the first valid IP in `X-Forwarded-For` is returned.
  3. If nothing usable is found, it falls back to `REMOTE_ADDR`.

Trusted proxies are configured through the `TRUSTED_PROXIES` environment variable — a comma- or whitespace-separated list of exact IPs and/or CIDR ranges (IPv4 and IPv6 both supported):

```
TRUSTED_PROXIES=10.0.0.5, 172.16.0.0/12
```

If `TRUSTED_PROXIES` is unset or empty, no proxy is ever trusted and `trustedProxyIp()` behaves exactly like `ip()`. Use `trustedProxyIp()` for rate limiting, audit logs, and anything security-relevant when deployed behind a proxy you control.

## File Uploads

### Reading uploaded files

`allFiles(): array` normalizes the `$_FILES` superglobal into `System\Http\UploadedFile` objects, keyed by field name. Array fields (`<input name="photos[]">`) become arrays of `UploadedFile` objects, nested to any depth. Fields where the user submitted nothing (`UPLOAD_ERR_NO_FILE`) are dropped from the result. The result is computed once and cached per request.

```php
$files = $request->allFiles();

if (isset($files['avatar'])) {
    $avatar = $files['avatar'];                 // UploadedFile
    $name   = $avatar->getClientFilename();     // 'photo.png' (client-supplied!)
    $size   = $avatar->getSize();               // bytes
    $error  = $avatar->getError();              // UPLOAD_ERR_OK etc.
}
```

### The `UploadedFile` API

| Method | Returns | Notes |
| --- | --- | --- |
| `getClientFilename(): string` | Original filename | Sent by the client — never trust it for filesystem paths |
| `getClientMediaType(): string` | Client-declared MIME type | Also client-controlled |
| `getSize(): int` | Size in bytes | |
| `getError(): int` | PHP `UPLOAD_ERR_*` constant | `UPLOAD_ERR_OK` (0) means success |
| `moveTo(string $targetPath): void` | — | Moves the temp file; creates the target directory if needed; throws `RuntimeException` on error, if already moved, or if the upload has an error code |
| `moveToUnique(string $targetPath, int $maxTries = 10000): string` | Final path | Like `moveTo()` but appends `-1`, `-2`, ... until the name is free; returns the path actually used |
| `movedPath(): ?string` | Destination path | `null` until the file has been moved |
| `validate(array $allowedExtensions = [], int $maxBytes = 0): bool` | `true` | Throws `RuntimeException` on upload error, oversize file, disallowed extension, or extension/MIME mismatch (checked via `finfo` for jpg/png/gif/webp/svg/pdf) |
| `getStreamContent(): string` | Raw file bytes | Empty string on error or missing temp file |

```php
public function upload(): Response
{
    $files = $this->req()->allFiles();
    $doc = $files['document'] ?? null;

    if ($doc === null || $doc->getError() !== UPLOAD_ERR_OK) {
        return $this->error('No file uploaded.', 422);
    }

    $doc->validate(['pdf', 'png', 'jpg'], 5 * 1024 * 1024);  // throws on failure

    $path = $doc->moveToUnique(storage_path('uploads') . '/' . $doc->getClientFilename());

    return $this->success(['stored_at' => $path]);
}
```

### `storeUploadedFile()` — sanitized storage helper

```php
$path = $request->storeUploadedFile($file, storage_path('uploads'));
$path = $request->storeUploadedFile($file, storage_path('uploads'), 'custom-name.pdf');
```

`storeUploadedFile(UploadedFile $file, string $destinationDir, ?string $filename = null): string` moves the file into `$destinationDir` and returns the full target path. If you don't supply `$filename`, the client filename is sanitized first — anything outside word characters, hyphens, and dots is collapsed to `-`, so `../../etc/passwd` can't escape the destination directory. If you *do* supply `$filename`, it is used as-is, so only pass names you generate yourself.

Two extra helpers read a file's content directly instead of moving it:

```php
$b64  = $request->inputFileBase64('avatar');   // base64-encoded content, or $default
$blob = $request->inputFileBlob('avatar');     // raw bytes, or $default
```

Both accept dot notation for nested fields and return the default when the field is missing or errored. See the [file uploads cookbook](/cookbook/file-uploads) for a full walkthrough.

## Request Attributes

Attributes are arbitrary values attached to the request object for the remainder of the cycle — the standard way for middleware to hand data to controllers:

```php
// In middleware
public function __invoke(Request $request, Response $response, callable $next, array $params = []): Response
{
    $user = $this->auth->userFromToken($request->header('Authorization', ''));
    $request->set('user', $user);

    return $next($request, $response);
}

// In the controller
public function profile(): Response
{
    $user = $this->req()->attr('user');   // null if never set
    return $this->success($user);
}
```

- `set(string $attribute, mixed $value): void` stores a value on this request instance.
- `attr(string $name): mixed` reads it back, returning `null` when the attribute was never set.

Because the same `Request` object flows through middleware into the controller, attributes are visible to everything downstream. See [Middleware](/essentials/middleware) for the pipeline details.

## Per-Request Caching

`cache(?string $prefix = null): RequestCache` returns a lazily-created `System\Utilities\RequestCache` — a thin, instance-based wrapper around the framework's `Cache` facade that is easier to mock in tests. The optional `$prefix` (applied on first call only, since the instance is memoized) is prepended to every key as `prefix:`.

```php
$stats = $request->cache('dashboard')->remember('stats', 300, function () {
    return $this->reports->computeStats();   // cached for 300 seconds
});
```

Available methods on `RequestCache`:

```php
$cache = $request->cache();

$cache->put('key', $value, 60);                    // store (ttl seconds, 0 = forever)
$cache->get('key', $default);                      // read
$cache->has('key');                                // bool
$cache->forget('key');                             // delete
$cache->remember('key', 60, fn () => compute());   // get-or-compute
$cache->increment('counter');                      // increment(string $key, int $amount = 1)
$cache->decrement('counter', 2);
$cache->putWithTags('key', $value, 60, ['users']); // tagged storage (tags not prefixed)
$cache->invalidateTag('users');                    // drop everything under a tag
$cache->flush();                                   // clear the entire cache store
$scoped = $cache->withPrefix('reports');           // new instance with a different prefix
```

Note that despite the name, entries are stored in the shared cache backend — the "request" part refers to the API being scoped to the request object, not to the entries' lifetime. Use TTLs and prefixes to keep keys organized. See [Cache](/features/cache) for the underlying store.

## Path and Method Introspection

```php
$request->getMethod();        // 'POST'
$request->getPath();          // '/users/5' — base path stripped, no query string
$request->getPath(0);         // '5'  — path segments, indexed from the END of the path
$request->getPath(1);         // 'users'
$request->getBasePath();      // '' or '/subdir' when the app lives in a subdirectory
```

`getPath(int $index = -1): ?string` returns the full path by default. With a non-negative index it returns individual segments **in reverse order** — index `0` is the last segment. Prefer [route parameters](/essentials/routing) over manual path parsing whenever possible.

::: warning Gotchas
- **`ip()` behind a proxy returns the proxy's address.** Use `trustedProxyIp()` *and* set `TRUSTED_PROXIES`, otherwise `X-Forwarded-For` is deliberately ignored (it is trivially spoofable by clients).
- **Client filename and MIME type are attacker-controlled.** Never build filesystem paths from `getClientFilename()` or trust `getClientMediaType()` — use `storeUploadedFile()`/`validate()` which sanitize and verify.
- **The merged input is cached.** `all()`/`input()` compute the merge once per request instance; mutating superglobals afterwards won't be reflected. Bodies override query values on key collisions (`$_POST` over `$_GET`, JSON over both).
- **`has()` does not support dot notation** — it checks top-level keys of the merged input only. Use `input('a.b') !== null` for nested checks.
- **`getPath($index)` indexes segments from the end**, not the beginning: for `/users/5/edit`, index `0` is `'edit'`.
- **An invalid JSON body is silently ignored** by `all()` (and `jsonInput()` returns the default). If you must distinguish "no body" from "malformed body", validate the raw input yourself.
- **`UploadedFile::moveTo()` throws** on a second move, on upload errors, and on filesystem failure — wrap uploads in try/catch or check `getError()` first.
:::

## Related

- [Responses](/essentials/responses)
- [Controllers](/essentials/controllers)
- [Middleware](/essentials/middleware)
- [Validation](/essentials/validation)
- [File Uploads Cookbook](/cookbook/file-uploads)
- [Cache](/features/cache)
- [Request API Reference](/api/request)
