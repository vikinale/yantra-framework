# Rate Limiting Routes

This recipe throttles requests with the built-in `rate.limit` middleware (`System\Security\Middleware\RateLimitMiddleware`) — a fixed-window limiter that rejects clients once they exceed a request count within a time window, returning **429 Too Many Requests** with `Retry-After` and `X-RateLimit-*` headers.

```php
$r->post('/api/login', 'Api\AuthController@login')
  ->middleware('rate.limit', ['limit' => 5, 'window' => 60]);  // 5 requests / 60s
```

## Applying it to routes

`rate.limit` is one of the eight built-in middleware aliases, so you can use it without registering anything. Attach the bare alias for the defaults (60 requests / 60 seconds), or pass a params array to configure it per route.

```php
// Default: 60 requests / 60s
$r->post('/api/data', 'Api\DataController@store')
  ->middleware('rate.limit');

// Custom limit for this route
$r->post('/api/login', 'Api\AuthController@login')
  ->middleware('rate.limit', ['limit' => 5, 'window' => 60, 'key' => 'login']);
```

Route params are passed as the **second argument** to `->middleware()` — the Laravel-style colon string (`'rate.limit:5,60'`) is **not** parsed by the router. The same applies to a group: capture the group and chain `->middleware()` with the params array.

```php
$r->group('/api', ['middleware' => ['auth.jwt']], function ($r) {
    $r->get('/me', 'Api\ProfileController@show');
})->middleware('rate.limit', ['limit' => 100, 'window' => 60]);
```

## Route params

`__invoke()` merges these route params over the middleware's constructor defaults:

| Param | Type | Default | Meaning |
|---|---|---|---|
| `limit` | int | `60` | Max requests allowed per window |
| `window` | int | `60` | Window length in **seconds** |
| `key` | string | `'global'` | Namespace for the counter — use distinct keys to give different routes independent buckets |
| `by` | string | `'ip+route'` | What to bucket by: `'ip'`, `'route'`, or `'ip+route'` |
| `message` | string | `'Too many requests…'` | Body message returned on 429 |
| `headers` | bool | `true` | Whether to emit `X-RateLimit-*` headers |
| `fileCounter` | bool | `false` | Use a file-backed counter instead of the session fallback |

## Reusable named limiters via the container

When several routes share the same policy, binding a pre-configured instance under its own alias keeps route definitions terse and the limit in one place. Register it in `config/dependencies.php`:

```php
// config/dependencies.php
use System\Security\Middleware\RateLimitMiddleware;

return [
    // Bind a configured limiter under a custom alias
    'rate.limit.login' => fn () => new RateLimitMiddleware(
        limit: 5,
        windowSeconds: 60,
        key: 'login',              // its own counter, separate from other limits
        message: 'Too many login attempts. Try again shortly.',
    ),
];
```

```php
// Then apply your custom alias to routes — no params needed
$r->post('/api/login', 'Api\AuthController@login')
  ->middleware('rate.limit.login');
```

The constructor arg is `windowSeconds` (the route param is `window`); everything else matches the param names above.

## How it behaves

On each request the middleware:

1. Builds an identifier from the `by` mode and a per-window bucket (`floor(now / window)`).
2. Increments the counter for that identifier. It uses **APCu** when available (fast, shared across processes), a **file counter** when `fileCounter` is enabled, and otherwise falls back to a **session-backed** counter.
3. If the count exceeds `limit`, it returns **429** via `ApiResponse::tooManyRequests()` with a `Retry-After` header (seconds until the window resets) and a meta block (`limit`, `window`, `reset_at`, `by`).
4. Otherwise, when `headers` is on, it adds the standard rate-limit headers and passes control to the next handler:

```
X-RateLimit-Limit:     60
X-RateLimit-Remaining: 42
X-RateLimit-Reset:     1751826000
```

Because the window is fixed, the counter resets abruptly at each boundary — a client can send up to `limit` requests in the last moment of one window and `limit` again in the first moment of the next.

::: warning Gotchas
- **Params go in the array, not a colon string.** `->middleware('rate.limit', ['limit' => 5])` works; `->middleware('rate.limit:5,60')` does not — the string becomes a literal alias that fails to resolve.
- **The window is fixed, not sliding.** Bursts can reach up to `2 × limit` across a window boundary. If you need smooth limiting, layer your own logic.
- **Give unrelated limits distinct `key` values.** Routes sharing the default `key` of `'global'` (and the same `by` identity) share one counter — a login limiter and an OTP limiter with the same key will drain each other.
- **Counter backend matters in production.** Without APCu (or the file counter), the fallback session counter is per-session and won't stop a client that drops its cookie — enable APCu for a shared, robust limit.
- **`window` is in seconds.** A `window` of 60 is per minute; there is no minutes/hours unit.
:::

## Related

- [Middleware](/essentials/middleware) — registering and applying middleware, and the built-in alias list
- [JWT API cookbook](/cookbook/jwt-api) — pair rate limiting with token auth on API routes
- [Security Overview](/security/overview)
- [Responses](/essentials/responses) — the 429 response shape
