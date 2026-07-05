# Rate Limiting Routes

This recipe throttles requests with the built-in `rate.limit` middleware (`System\Security\Middleware\RateLimitMiddleware`) — a fixed-window limiter that rejects clients once they exceed a request count within a time window, returning **429 Too Many Requests** with `Retry-After` and `X-RateLimit-*` headers.

```php
$r->post('/api/login', 'Api\AuthController@login')
  ->middleware('rate.limit');   // default: 60 requests / 60s
```

::: warning Per-route params are not honored by the `rate.limit` alias
When applied via the `rate.limit` alias, the middleware's `__invoke()` **ignores** any route-level params array and uses its **constructor defaults** (60 requests / 60 seconds, key `'global'`). Passing `->middleware('rate.limit', ['limit' => 5])` does **not** change the limit today.

To use a custom limit, register a configured instance in `config/dependencies.php` and bind it under your own alias (see below). Only the container-configured instance's constructor values take effect.
:::

## Applying it to routes

`rate.limit` is one of the eight built-in middleware aliases, so you can use it without registering anything. Attach the bare alias to a single route or a whole group for the default 60/60s limit.

```php
// Single route
$r->post('/api/data', 'Api\DataController@store')
  ->middleware('rate.limit');

// A whole group
$r->group('/api', ['middleware' => ['auth.jwt', 'rate.limit']], function ($r) {
    $r->get('/me', 'Api\ProfileController@show');
});
```

## Custom limits via the container

Because the alias ignores route params, a custom limit must come from a `RateLimitMiddleware` **constructed with your values** and bound under your own alias. Register a configured instance in `config/dependencies.php`:

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
// Then apply your custom alias to routes
$r->post('/api/login', 'Api\AuthController@login')
  ->middleware('rate.limit.login');
```

## Constructor parameters

`RateLimitMiddleware`'s constructor accepts these values (used by any instance you build and bind):

| Param | Type | Default | Meaning |
|---|---|---|---|
| `limit` | int | `60` | Max requests allowed per window |
| `windowSeconds` | int | `60` | Window length in **seconds** |
| `key` | string | `'global'` | Namespace for the counter — use distinct keys to give different route groups independent buckets |
| `by` | string | `'ip+route'` | What to bucket by: `'ip'`, `'route'`, or `'ip+route'` |
| `message` | string | `'Too many requests…'` | Body message returned on 429 |
| `emitHeaders` | bool | `true` | Whether to emit `X-RateLimit-*` headers |

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
- **The window is fixed, not sliding.** Bursts can reach up to `2 × limit` across a window boundary. If you need smooth limiting, layer your own logic.
- **Give unrelated limits distinct `key` values.** All routes sharing the default `key` of `'global'` (and the same `by` identity) share one counter — a login limiter and an OTP limiter with the same key will drain each other. Set `key` when constructing a configured instance.
- **The bare `rate.limit` alias always applies its constructor defaults (60 / 60s, key `'global'`).** Route params are ignored — build and bind a configured instance for anything else.
- **Counter backend matters in production.** Without APCu (or the file counter), the fallback session counter is per-session and won't stop a client that drops its cookie — enable APCu for a shared, robust limit.
- **`windowSeconds` is in seconds.** A `windowSeconds` of 60 is per minute; there is no minutes/hours unit.
:::

## Related

- [Middleware](/essentials/middleware) — registering and applying middleware, and the built-in alias list
- [JWT API cookbook](/cookbook/jwt-api) — pair rate limiting with token auth on API routes
- [Security Overview](/security/overview)
- [Responses](/essentials/responses) — the 429 response shape
