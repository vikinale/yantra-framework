# API Reference: Request

`System\Http\Request`

A read-mostly wrapper over PHP's request superglobals (`$_GET`, `$_POST`, `$_SERVER`, `$_FILES`, and the raw `php://input` body). It merges query, form, and JSON body into a single input bag, exposes headers, uploaded files, client IP resolution, and a small per-request attribute store used by middleware. The framework constructs one per request and injects it into controllers; you can also build one with `Request::fromGlobals()`. For a guide, see [Requests](/essentials/requests).

## Method Table

### Construction

| Signature | Returns | Description |
| --- | --- | --- |
| `__construct()` | — | Captures the base path from `SCRIPT_NAME`. |
| `static fromGlobals(): self` | `self` | Convenience factory (identical to `new Request()`). |

### Input (merged GET + POST + JSON/body)

`inputs()` merges `$_GET`, then `$_POST`, then a decoded JSON body (when `Content-Type: application/json`) or a parsed `PUT`/`PATCH`/`DELETE` body. The result is cached for the request.

| Signature | Returns | Description |
| --- | --- | --- |
| `all(): array` | `array` | Alias for `inputs()` — the full merged input bag. |
| `inputs(): array` | `array` | Merged & cached GET/POST/body input. |
| `input(string $key, $default = null)` | mixed | One value by key; supports `dot.notation` for nested arrays. |
| `get(string $key, $default = null)` | mixed | Alias for `input()`. |
| `inputGet(string $key, $default = null)` | mixed | Alias for `input()`. |
| `only(array $keys, array $defaults = []): array` | `array` | Subset of inputs for the given keys (with optional per-key defaults). |
| `has(string $key): bool` | `bool` | Whether the (top-level) key exists in the merged inputs. |
| `jsonInput(string $key, $default = null)` | mixed | Read a key straight from the raw JSON body (dot notation). |

### GET / POST specific

| Signature | Returns | Description |
| --- | --- | --- |
| `getQuery(?string $key = null, $default = null)` | mixed | Raw `$_GET` (all, or one key). |
| `param(?string $name, $default = null)` | mixed | Raw `$_GET` (all, or one key). |
| `getOnly(?string $key = null, $default = null)` | mixed | `$_GET` only, with dot notation. |
| `postOnly(?string $key = null, $default = null)` | mixed | `$_POST` only, with dot notation. |
| `post(?string $key = null, $default = null)` | mixed | Alias for `postOnly()`. |

### Method & path

| Signature | Returns | Description |
| --- | --- | --- |
| `getMethod(): string` | `string` | Uppercased HTTP method (`GET` default). |
| `getPath(int $index = -1): ?string` | `?string` | Request path with base path stripped; with `$index ≥ 0`, the Nth segment counted **from the end**. |
| `getBasePath(): string` | `string` | Base path derived from the front controller's `SCRIPT_NAME`. |
| `isGet` / `isPost` / `isPut` / `isDelete` | `bool` | Method predicates. |
| `isAjax(): bool` | `bool` | `X-Requested-With: XMLHttpRequest`? |
| `acceptsJson(): bool` | `bool` | Does the `Accept` header contain `application/json`? |

### Headers

| Signature | Returns | Description |
| --- | --- | --- |
| `header(string $name, $default = null): ?string` | `?string` | One header (case-insensitive), with special handling for `Content-Type`/`Content-Length`. |
| `headers(): array` | `array` | All request headers (`getallheaders()` when available, else derived from `$_SERVER`). |
| `userAgent(?string $default = null): ?string` | `?string` | The `User-Agent` header. |

### Uploaded files

| Signature | Returns | Description |
| --- | --- | --- |
| `allFiles(): array` | `array` | Normalized upload tree of `UploadedFile` objects (cached; `UPLOAD_ERR_NO_FILE` entries dropped). |
| `storeUploadedFile(UploadedFile $file, string $destinationDir, ?string $filename = null): string` | `string` | Move an upload to a directory (sanitizing the name); returns the final path. |
| `inputFileBase64(string $name, $default = null)` | mixed | Base64 of an uploaded file's contents (dot notation), or default. |
| `inputFileBlob(string $name, $default = null)` | mixed | Raw bytes of an uploaded file (dot notation), or default. |

### Client / proxy / IP

| Signature | Returns | Description |
| --- | --- | --- |
| `ip(): ?string` | `?string` | `REMOTE_ADDR` verbatim (no proxy trust). |
| `trustedProxyIp(): ?string` | `?string` | Client IP honoring `X-Forwarded-For` **only** when `REMOTE_ADDR` is in a `TRUSTED_PROXIES` range. |

### Attributes & request cache

| Signature | Returns | Description |
| --- | --- | --- |
| `set(string $attribute, mixed $value): void` | `void` | Store a per-request attribute (used by middleware to pass data downstream). |
| `attr(string $name): mixed` | mixed | Read a per-request attribute (`null` if unset). |
| `cache(?string $prefix = null): RequestCache` | `RequestCache` | Lazily create/return the per-request cache. |

## Selected examples

### Dot notation and defaults

`input()` (and its aliases) resolves nested keys with dots and falls back to a default:

```php
$city = $request->input('address.city', 'Unknown');
$page = (int) $request->get('page', 1);
$creds = $request->only(['email', 'password']);
```

### JSON bodies are merged automatically

When the request `Content-Type` is `application/json`, `inputs()` decodes the body and merges it over GET/POST — so `input('email')` works the same for a JSON API call and a form post. `jsonInput()` is the escape hatch that reads the raw body directly, bypassing the merge.

### Path segments count from the end

```php
// URL: /shop/products/42
$request->getPath();     // "/shop/products/42"
$request->getPath(0);    // "42"   (last segment)
$request->getPath(1);    // "products"
```

### Trusting proxies

`ip()` returns `REMOTE_ADDR` as-is. `trustedProxyIp()` walks `X-Forwarded-For` and returns the first valid client IP **only** when the immediate peer is inside a CIDR range listed in the `TRUSTED_PROXIES` environment variable (comma/space separated). If no proxies are trusted, it returns `REMOTE_ADDR`.

::: warning Gotchas
- **`ip()` is spoofable behind a proxy** — it never consults forwarding headers. Use `trustedProxyIp()` (and configure `TRUSTED_PROXIES`) when you sit behind a load balancer.
- **`input()`/`has()` read the *merged* bag** (GET + POST + body); `getOnly()`/`postOnly()` read a single superglobal. Pick deliberately when a key could appear in more than one place.
- **`has()` only checks top-level keys** — it does not understand dot notation.
- **`getPath($index)` indexes from the end**, not the start.
- **`allFiles()` silently drops empty file inputs** (`UPLOAD_ERR_NO_FILE`), so a field the user left blank simply won't appear.
:::

## Related

- [Requests guide](/essentials/requests)
- [Controllers](/essentials/controllers)
- [Middleware](/essentials/middleware)
- [API Reference: Response](/api/response)
- [API Reference: Validator](/api/validator)
