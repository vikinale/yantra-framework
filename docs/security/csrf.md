# CSRF Protection

Cross-site request forgery protection in Yantra is built around `System\Security\Csrf` — a static, session-backed token store with per-key scoping and time-based expiry. You can drive it three ways: automatically via the `sec.csrf` middleware, per-form via the `csrf_field()` / `csrf_token()` helpers, or explicitly in controllers with `validateCsrf()`.

```php
<form method="POST" action="/contact">
    <?= csrf_field() ?>
    <!-- Outputs: <input type="hidden" name="_csrf" value="..."> -->
    <input type="text" name="message">
    <button type="submit">Send</button>
</form>
```

With `sec.csrf` in your global middleware, that form is protected — nothing else required.

## Generating tokens

```php
use System\Security\Csrf;

// Default scope, 32 random bytes (64 hex chars), 15-minute TTL
$token = Csrf::token();

// Scoped token with custom size and TTL
$token = Csrf::token('login', bytes: 32, ttlSeconds: 3600);
```

`Csrf::token(string $key = 'default', int $bytes = 32, int $ttlSeconds = 900)` gets or creates a token for the given key. Tokens are stored in the session under `csrf.<key>` together with an `issued_at` timestamp and their TTL. If the stored token is missing **or expired**, a fresh one is minted; otherwise the existing token is returned, so repeated calls within the TTL are stable.

The default TTL is **900 seconds (15 minutes)** — `Csrf::DEFAULT_TTL` in the source.

## Validating tokens

```php
use System\Security\Csrf;

$valid = Csrf::validate($submittedToken, 'login', rotateOnSuccess: true);
```

`Csrf::validate(string $providedToken, string $key = 'default', bool $rotateOnSuccess = false, int $ttlSeconds = 900)` returns `true` only when:

1. the provided token is non-empty,
2. a structured token exists in the session for that key,
3. the token has **not expired** (`issued_at + ttl` is still in the future), and
4. the values match under a constant-time comparison (`Crypto::hashEquals`).

Two rotation behaviors are worth knowing:

- **On mismatch, the stored token is always replaced** with a fresh random one — a failed guess burns the token.
- With `rotateOnSuccess: true`, a successful validation also replaces the token, giving you single-use tokens.

Expired tokens are always invalid; the next call to `Csrf::token()` will mint a replacement.

Utility methods:

```php
Csrf::clear('login');           // remove the token for a scope
$secs = Csrf::remainingTtl();   // seconds until expiry (0 if missing/expired)
```

## Helpers

```php
$token = csrf_token();  // Csrf::token() for the 'default' scope
echo csrf_field();      // '<input type="hidden" name="_csrf" value="...">' (HTML-escaped)
```

Both helpers operate on the `default` scope — the same scope the `sec.csrf` middleware validates.

## Controller validation

The base controller provides a shortcut that reads the `_csrf` input from the current request:

```php
public function store()
{
    if (!$this->validateCsrf('form_scope')) {
        return $this->error('Invalid CSRF token', 403);
    }
    // ... handle the form
}
```

The signature is `validateCsrf(string $scope, bool $rotate = true)` — note it rotates on success **by default**, so each rendered form gets a fresh token. See [Controllers](/essentials/controllers).

## The `sec.csrf` middleware

`CsrfMiddleware` (alias `sec.csrf`) checks every **POST, PUT, PATCH, and DELETE** request; `GET` and `HEAD` pass through. It looks for the token in the `X-CSRF-Token` request header first, then falls back to the `_csrf` input, and validates it against the `default` scope. On failure it short-circuits with:

```
HTTP/1.1 419
Content-Type: text/plain; charset=utf-8

CSRF validation failed.
```

For AJAX requests, send the token as a header:

```js
fetch('/api/action', {
    method: 'POST',
    headers: { 'X-CSRF-Token': document.querySelector('input[name=_csrf]').value },
});
```

::: warning Gotchas
- There is **no `Csrf::rotate()` method**. Rotation happens via the `rotateOnSuccess` argument to `validate()` (or the `$rotate` argument to the controller's `validateCsrf()`), and automatically on any failed match.
- The middleware validates the **`default` scope only**. If you issue tokens with `Csrf::token('login')`, validate them yourself with `Csrf::validate($t, 'login')` or `$this->validateCsrf('login')` — the middleware won't accept them.
- Tokens expire after 15 minutes by default. Long-lived forms (drafts, multi-step wizards) should pass a larger `ttlSeconds` to both `token()` and `validate()`.
- Legacy raw-string tokens (from older framework versions) are rejected by `validate()` and replaced on the next `token()` call.
:::

## Related

- [Security Overview](/security/overview)
- [Authentication](/security/authentication)
- [Middleware](/essentials/middleware)
- [Controllers](/essentials/controllers)
- [Session](/essentials/session)
