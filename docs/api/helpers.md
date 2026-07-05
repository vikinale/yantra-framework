# Helpers API Reference

Global procedural functions defined in `src/System/functions.php`. Every function is guarded by `function_exists()`, so an application can override any of them by defining its own version before the file loads. This page enumerates the complete set, grouped by category, with signatures and one-line descriptions. For narrative usage and the `System\Helpers\*` static classes, see the [Helpers guide](/features/helpers).

## Config & Environment

| Function | Returns | Description |
| --- | --- | --- |
| `config(string $key, mixed $default = null)` | `mixed` | Read a config value via `Config::get()` (dot notation). To *set*, use `Config::set()` directly. |
| `env(string $key, mixed $default = null)` | `mixed` | Read an environment variable. `true`/`false`/`null`/`empty` (optionally parenthesized) normalize to PHP equivalents; `'0'`/`'1'` stay strings. |

## Paths

All return absolute filesystem paths; absolute `$append` inputs (Unix, Windows drive, UNC) are returned as-is.

| Function | Returns | Description |
| --- | --- | --- |
| `base_path(string $append = '')` | `string` | Path relative to the project root (`BASEPATH`). |
| `app_path(string $append = '')` | `string` | Path relative to the application directory (`APPPATH`). |
| `storage_path(string $append = '')` | `string` | `base_path('storage')` plus `$append`. |
| `public_path(string $append = '')` | `string` | `base_path('public')` plus `$append`. |
| `theme_path(string $append = '')` | `string` | Filesystem path inside the active theme (`app.theme.active`, default `default`). |
| `path_to_url(string $path, ?string $baseUrl = null, ?string $publicRootPath = null)` | `?string` | Map an absolute path under the public root to a full URL; `null` if it can't be mapped. |

## URLs

| Function | Returns | Description |
| --- | --- | --- |
| `base_url(?string $append = '')` | `string` | Application base URL. Prefers `config('app.url')`, else derives proto/host from the request plus the `app.site` sub-directory. |
| `site_url(?string $append = '')` | `string` | Alias for `base_url()`. |
| `site_name(?string $append = '')` | `string` | The configured `app.site` sub-directory value, optionally with an appended path. |
| `assets(?string $append = '')` | `string` | Public asset URL; base from `config('app.assets.base')` (default `assets`). |
| `theme_url(?string $append = '')` | `string` | Web URL for active-theme assets, served from `/themes/{active}/...`. |
| `current_url()` | `string` | Full URL of the current request (host + `REQUEST_URI`). Never trusts `X-Forwarded-Host`. |
| `is_https()` | `bool` | Detect HTTPS, honoring `X-Forwarded-Proto` from reverse proxies. |
| `path_is(string $needle)` | `bool` | `true` if the request path exactly equals `$needle` (trailing slashes ignored). |
| `path_starts(string $prefix)` | `bool` | `true` if the request path starts with `$prefix`. |

## Responses & Navigation

| Function | Returns | Description |
| --- | --- | --- |
| `redirect(string $url, int $code = 302)` | `Response` | Create a redirecting `System\Http\Response`. |
| `back(string $fallback = '/')` | `Response` | Redirect to `HTTP_REFERER`, or the fallback URL. |
| `abort(int $code, string $message = '')` | `never` | Set the response code and throw `HttpException`; message defaults to the standard status phrase. |
| `json(mixed $data, int $status = 200)` | `Response` | Create a JSON `Response`. |

## Security & Escaping

| Function | Returns | Description |
| --- | --- | --- |
| `e(?string $value)` | `string` | Escape for HTML text (`ENT_QUOTES \| ENT_SUBSTITUTE`, UTF-8). `null` → empty string. |
| `esc_attr(?string $value)` | `string` | Escape for HTML attribute values (same flags as `e()`). |
| `esc_url(?string $url)` | `string` | Sanitize a URL for output: strip control chars, block `javascript:`/`data:` schemes, then HTML-escape. |
| `csrf_token()` | `string` | Current CSRF token from `System\Security\Csrf`. |
| `csrf_field()` | `string` | Hidden `<input name="_csrf">` field carrying the token. |

See the [Security API Reference](/api/security) for `Csrf`, `Password`, and `Crypto`.

## Session & Auth

| Function | Returns | Description |
| --- | --- | --- |
| `session(?string $key = null, mixed $default = null)` | `mixed` | No args → the `SessionStore` instance; one arg → read; two args (non-null value) → write. |
| `old(string $key, mixed $default = '')` | `mixed` | Read flashed "old input" (`_old_input.*`) after a validation redirect (a destructive flash pull). |
| `auth_user()` | `?array` | The authenticated user's session data (the `auth` key), or `null`. |
| `auth_check()` | `bool` | `true` when a user is logged in. |

See the [SessionStore API Reference](/api/session-store).

## Routing

| Function | Returns | Description |
| --- | --- | --- |
| `route(string $name, array $params = [], array $query = [])` | `string` | Generate a URL for a named route via `UrlGenerator`, filling `{param}` placeholders and appending query params. |

## Hooks

WordPress-style actions and filters delegating to [`System\Hooks`](/api/hooks).

| Function | Returns | Description |
| --- | --- | --- |
| `add_action(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1)` | `void` | Register an action listener. |
| `do_action(string $hook, mixed ...$args)` | `void` | Fire all listeners for an action. |
| `add_filter(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1)` | `void` | Register a value filter. |
| `apply_filters(string $hook, mixed $value, mixed ...$args)` | `mixed` | Pass a value through all registered filters (the canonical global name). |
| `apply_filter(string $hook, mixed $value, mixed ...$args)` | `mixed` | Backward-compatible **singular** alias for `apply_filters()`. |

## Collections

| Function | Returns | Description |
| --- | --- | --- |
| `collect(array\|Collection $items = [])` | `Collection` | Create a `System\Support\Collection`. |

## Utilities

| Function | Returns | Description |
| --- | --- | --- |
| `out(string $msg)` | `void` | Write a message plus newline to `STDOUT` (CLI helper). |
| `okFile(string $file)` | `bool` | `true` when a file exists and is readable. |
| `dd(mixed ...$vars)` | `never` | Dump variables and exit. Throws `RuntimeException` in `production` instead of leaking data. |
| `dt(?string $v)` | `string` | Escape a nullable string, returning `'-'` when empty. |
| `now()` | `string` | Current datetime in `Y-m-d H:i:s`. |
| `normalize_email(string $email)` | `string` | Trim, lowercase, and validate an email; `''` when invalid. |
| `pick_keys(array $arr, array $keys)` | `array` | Return only the listed keys, trimming string values. |
| `cls(bool $cond, string $true, string $false = '')` | `string` | Ternary shorthand for building class-attribute strings. |
| `_include(string $filePath, array $variables = [], bool $print = true)` | `?string` | Render a template file in an isolated scope (`EXTR_SKIP`). Returns the output; prints it by default; `null` if the file is unreadable. |

## Examples

### `session()` — the read/write overload

```php
session('cart.count', 3);   // set (two non-null args)
$n = session('cart.count'); // read
$store = session();         // the SessionStore instance
```

`session('key', null)` is a **read**, not a delete — the write branch only fires when the second argument is non-null.

### `env()` type coercion

```php
env('APP_DEBUG', false);   // "true"  → true, "false" → false
env('WORKERS', 1);         // "0"/"1" stay strings — cast if you need int/bool
```

### Escaping in views

```php
<h1><?= e($post->title) ?></h1>
<a href="<?= esc_url($post->link) ?>"><?= dt($post->subtitle) ?></a>
<form method="post"><?= csrf_field() ?></form>
```

::: warning Gotchas
- **Two CSRF token systems exist.** `csrf_token()` / `csrf_field()` use `System\Security\Csrf` with field `_csrf`; `FormHelper` uses its own TTL bag with field `_csrf_token`. Pick one per form.
- **`session('key', null)` is a read**, not a write or delete.
- **`old()` is a destructive flash pull** — it reads *and removes* the `_old_input.*` key, and it is distinct from `FormHelper::old()` (a separate `_yantra_old_input` bag).
- **`dd()` throws in production** rather than dumping; never rely on it for production logging.
- **`env()` keeps `'0'` / `'1'` as strings**; only the words `true`/`false`/`null`/`empty` are coerced.
:::

## Related

- [Helpers guide](/features/helpers)
- [SessionStore API Reference](/api/session-store)
- [Hooks API Reference](/api/hooks)
- [Security API Reference](/api/security)
- [Cache API Reference](/api/cache)
