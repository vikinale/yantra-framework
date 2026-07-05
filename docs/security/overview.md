# Security Overview

Yantra ships security as a set of small, composable middleware rather than a monolithic "security layer". Each middleware does one job — normalizing requests, setting response headers, hardening cookies, validating CSRF tokens, logging audit trails, or issuing CSP nonces — and you opt in by listing them in your global middleware config. This page tours the built-in security middleware, explains what each protects against, and shows a recommended production configuration.

```php
// App/Config/middleware.php — a security-first global pipeline
return [
    'global' => [
        'sec.normalize',   // reject malformed requests first
        'sec.headers',     // baseline security headers
        'sec.cookies',     // harden all cookies
        'sec.csrf',        // CSRF token validation
        'sec.audit',       // request/response audit log
    ],
];
```

## The security middleware stack

All built-in security middleware live under `System\Security\Middleware` and are registered with short aliases you can use in route and global config:

| Alias | Class | Protects against |
|---|---|---|
| `sec.normalize` | `RequestNormalizationMiddleware` | Non-standard methods, oversized bodies, encoded payload smuggling |
| `sec.headers` | `SecurityHeadersMiddleware` | MIME sniffing, referrer leaks, unwanted browser features |
| `sec.cookies` | `CookieHardeningMiddleware` | Session fixation, cookie theft via XSS, CSRF via lax cookies |
| `sec.csrf` | `CsrfMiddleware` | Cross-site request forgery on state-changing requests |
| `sec.audit` | `AuditMiddleware` | Blind spots — gives you a forensic trail |
| `sec.csp` | `CspNonceMiddleware` | XSS via injected inline scripts |
| `auth.jwt` | `JwtAuthMiddleware` | Unauthenticated API access (see [JWT](/security/jwt)) |
| `rate.limit` | `RateLimitMiddleware` | Brute force and abuse |

### RequestNormalization (`sec.normalize`)

Runs first and rejects requests that no legitimate client should send:

- HTTP methods outside `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD` are refused with **405 Method Not Allowed**.
- Request bodies larger than **1 MB** (`Content-Length`) are refused with **413 Payload Too Large**.
- Requests carrying a `Content-Encoding` header are refused with **415 Unsupported Content Encoding**, preventing compressed-payload smuggling.

### SecurityHeaders (`sec.headers`)

Adds baseline response headers on every request:

- `X-Content-Type-Options: nosniff` — stops browsers from MIME-sniffing responses into executable types.
- `Referrer-Policy: strict-origin-when-cross-origin` — limits referrer leakage to third-party sites.
- `Permissions-Policy: camera=(), microphone=(), geolocation=()` — disables sensitive browser features by default.
- `Strict-Transport-Security: max-age=15552000; includeSubDomains` — added **only when the request is HTTPS** (detected via `$_SERVER['HTTPS']` or `X-Forwarded-Proto: https`).

Note that this middleware deliberately does **not** set a `Content-Security-Policy` header — CSP requirements vary too widely between applications. Use `sec.csp` (below) or your own middleware for that.

### CookieHardening (`sec.cookies`)

Two-phase cookie protection:

1. **Before the pipeline runs** it hardens PHP's session cookie settings via `ini_set`: `session.use_strict_mode=1` (anti session-fixation), `session.cookie_httponly=1`, `session.cookie_secure` when HTTPS is detected, and `session.cookie_samesite` (default `Lax`). Because this must happen before `session_start()`, keep `sec.cookies` early in the global stack.
2. **After the pipeline runs** it rewrites every outgoing `Set-Cookie` header to enforce `SameSite` (default `Lax`), `Secure` (when HTTPS, or always when `SameSite=None`), and `HttpOnly` by default. Cookies that JavaScript legitimately needs can be excluded — the default exclusion list is `['csrf_token']`.

The constructor accepts `(string $sameSite = 'Lax', bool $httpOnlyDefault = true, array $httpOnlyFalseNames = ['csrf_token'], bool $rewriteSetCookieHeaders = true)` if you need different defaults.

### Csrf (`sec.csrf`)

Validates a CSRF token on every `POST`, `PUT`, `PATCH`, and `DELETE` request, read from the `X-CSRF-Token` header or the `_csrf` form field. Invalid or missing tokens get a **419** response. Safe methods (`GET`, `HEAD`) pass through untouched. Full details on tokens, TTLs, and helpers are on the [CSRF page](/security/csrf).

### Audit (`sec.audit`)

Logs a structured JSON line per request, per response, and per uncaught exception (which it re-throws after logging). Each line carries a server-generated request id (`rid`) so you can correlate the entries:

- `request` — `rid`, HTTP method, path, client IP
- `response` — `rid`, status code
- `exception` — `rid`, error message, file, line

Entries are JSON objects with `ts` (ISO-8601), `channel` (default `security`), `event`, and `ctx`, written via PHP's `error_log()` — no file handles to manage, and they land wherever your SAPI's error log points.

For ad-hoc security events (denied logins, permission failures) there is also a standalone static logger, `System\Security\Audit\Audit`:

```php
use System\Security\Audit\Audit;

Audit::log('auth_denied', ['code' => 401, 'path' => $_SERVER['REQUEST_URI'] ?? '']);
// → {"ts":"2026-07-05T10:00:00+00:00","channel":"security","event":"auth_denied","ctx":{...}}
```

`Audit::log(string $event, array $ctx = [])` writes the same JSON-line format (`ts`, `channel: "security"`, `event`, `ctx`) through `error_log()`.

### CspNonce (`sec.csp`)

Sets a full `Content-Security-Policy` header with a per-request nonce for `script-src`, so only `<script nonce="...">` tags you render can execute — injected inline scripts are blocked. Base directives include `default-src 'self'`, `base-uri 'self'`, `object-src 'none'`, `frame-ancestors 'none'`, `form-action 'self'`, `img-src 'self' data:`, `script-src 'self' 'nonce-...'`, and `upgrade-insecure-requests`.

The middleware takes a profile (`web` by default). The `admin` profile is stricter — it adds `frame-src 'none'` and tighter `font-src`/`media-src`. Apply the `sec.csp` alias to an admin group; since it runs later in the chain, its header wins:

```php
$r->group('/admin', ['middleware' => ['sec.csp']], function ($r) {
    $r->get('/dashboard', 'AdminController@index');
});
```

The nonce itself comes from `System\Security\Csp\CspNonce`, which exposes a single method:

```php
use System\Security\Csp\CspNonce;

$nonce = CspNonce::get(); // 16 random bytes, base64url-encoded, memoized per request
```

`CspNonce::get()` returns the same value for the whole request, so the nonce your view renders always matches the one the middleware put in the header:

```php
<script nonce="<?= CspNonce::get() ?>">
    // this inline script is allowed by the CSP
</script>
```

## Recommended production configuration

Global middleware is read from the `middleware` config (`App/Config/middleware.php`), key `global`; groups and aliases live in `config/middleware.php` at the project root (see [Middleware](/essentials/middleware)). A solid production baseline:

```php
// App/Config/middleware.php
return [
    'global' => [
        'sec.normalize',  // 1. reject garbage before doing any work
        'sec.headers',    // 2. baseline headers on every response
        'sec.cookies',    // 3. harden session ini before session_start()
        'sec.csrf',       // 4. block forged state-changing requests
        'sec.audit',      // 5. forensic trail for everything that got this far
    ],
];
```

Add `'sec.csp'` if your templates are ready for nonce-based scripts, and apply `'auth.jwt'` / `'rate.limit'` per route group rather than globally:

```php
$r->group('/api', ['middleware' => ['auth.jwt', 'rate.limit']], function ($r) {
    // ...
});
```

::: warning Gotchas
- `sec.cookies` must run **before** anything starts the session — its `ini_set` calls are ineffective after `session_start()`. Keep it near the top of the global stack.
- `sec.headers` does not set `Content-Security-Policy`; if you skip `sec.csp`, you have no CSP at all.
- HSTS is only emitted when HTTPS is detected. Behind a TLS-terminating proxy, make sure `X-Forwarded-Proto: https` reaches PHP.
- `AuthGuardMiddleware`, `GuestOnlyMiddleware`, and `CorsMiddleware` ship **without** built-in aliases — register your own alias or use the FQCN (see [Authentication](/security/authentication)).
:::

## Related

- [CSRF Protection](/security/csrf)
- [Authentication](/security/authentication)
- [JWT](/security/jwt)
- [Crypto & Passwords](/security/crypto)
- [Middleware](/essentials/middleware)
- [Rate Limiting cookbook](/cookbook/rate-limiting)
