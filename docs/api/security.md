# Security

Reference for the framework's low-level security primitives: CSRF tokens, HS256 JWTs, password hashing, cryptographic helpers, and login throttling. These are small, dependency-free static classes under the `System\Security` namespace. For narrative guides see [CSRF](/security/csrf), [JWT](/security/jwt), and [Crypto & Passwords](/security/crypto).

---

## Csrf

`System\Security\Csrf`

Time-based CSRF tokens stored in the session under `csrf.<key>`. A token carries an issued-at timestamp and TTL; `token()` mints a fresh one when the stored token is missing or expired, and `validate()` rejects expired or mismatched tokens using a constant-time comparison. On a **failed** validation the stored token is rotated automatically (invalidating the compromised value).

| Method | Returns | Description |
| --- | --- | --- |
| `token(string $key = 'default', int $bytes = 32, int $ttlSeconds = 900)` | `string` | Get the current token for `$key`, minting a new one if missing or expired. |
| `validate(string $providedToken, string $key = 'default', bool $rotateOnSuccess = false, int $ttlSeconds = 900)` | `bool` | Constant-time compare against the stored token; fails on mismatch, empty input, or expiry. Rotates the token on failure, and also on success when `$rotateOnSuccess` is `true`. |
| `clear(string $key = 'default')` | `void` | Remove the stored token for `$key`. |
| `remainingTtl(string $key = 'default')` | `int` | Seconds until the current token expires (`0` if missing/expired). |

```php
use System\Security\Csrf;

// In a form (or via the csrf_field() helper):
$token = Csrf::token();

// On submit:
if (!Csrf::validate($request->input('_csrf'))) {
    abort(419, 'CSRF token mismatch');
}
```

::: warning
There is **no `Csrf::rotate()` method.** Rotation happens implicitly: always on a failed `validate()`, and on success only when you pass `rotateOnSuccess: true`. The default field name used by `csrf_field()` is `_csrf`. Note `FormHelper` maintains a separate token bag with field `_csrf_token` — see the [Helpers gotchas](/features/helpers).
:::

---

## Jwt

`System\Security\Jwt\Jwt`

A dependency-free **HS256** (HMAC-SHA256) JWT encoder/decoder. The class exposes exactly two methods — there is no generic `encode`/`decode`/`verify`.

| Method | Returns | Description |
| --- | --- | --- |
| `encodeHS256(array $payload, string $secret, array $header = [])` | `string` | Build a compact JWT signed with HMAC-SHA256. Header defaults to `{"typ":"JWT","alg":"HS256"}`; `$header` is merged over it. Throws `JwtException` on empty secret or JSON failure. |
| `decodeHS256(string $jwt, string $secret, int $leewaySeconds = 0, bool $throw = false)` | `?array` | Verify signature and time claims, returning the payload or `null` on failure. Pass `throw: true` to raise `JwtException` with the reason. |

```php
use System\Security\Jwt\Jwt;

$token = Jwt::encodeHS256(['sub' => 42, 'exp' => time() + 3600], $secret);

$payload = Jwt::decodeHS256($token, $secret);            // null on failure
$payload = Jwt::decodeHS256($token, $secret, leewaySeconds: 30, throw: true);
```

Verification rejects tokens without exactly 3 parts, invalid base64url/JSON, any `alg` other than `HS256` (blocking `alg:none` and algorithm-confusion), or a failed constant-time signature check. The `nbf`, `iat`, and `exp` claims are validated only when present and numeric.

::: warning
`Jwt` signs/verifies **HS256 only**, while `JwtAuthMiddleware` (`auth.jwt`) verifies **RS256** — tokens from `encodeHS256()` will be rejected by that middleware. See the [JWT guide](/security/jwt) for the full story.
:::

---

## Password

`System\Security\Password`

Password hashing that prefers **Argon2id** when the PHP build supports it, falling back to **bcrypt** (cost 12). Verification auto-detects the algorithm from the stored hash.

| Method | Returns | Description |
| --- | --- | --- |
| `hash(string $password, array $options = [])` | `string` | Hash a password (Argon2id if available, else bcrypt). Throws `InvalidArgumentException` on empty input, `RuntimeException` on failure. `$options` overrides the algorithm defaults. |
| `verify(string $password, string $hash)` | `bool` | Constant-time verify. Returns `false` for an empty hash. |
| `needsRehash(string $hash, array $options = [])` | `bool` | Whether the hash was made with weaker parameters than the current defaults and should be re-hashed. |

```php
use System\Security\Password;

$hash = Password::hash($plain);

if (Password::verify($plain, $user->password_hash)) {
    if (Password::needsRehash($user->password_hash)) {
        $user->update(['password_hash' => Password::hash($plain)]);
    }
}
```

Argon2id defaults: `memory_cost` 131072 KiB (128 MB), `time_cost` 4, `threads` 2.

---

## Crypto

`System\Security\Crypto`

Thin, safe wrappers over PHP's cryptographic primitives — random bytes, hex tokens, constant-time comparison, and HMAC-SHA256. Used internally by `Csrf` and `Jwt`.

| Method | Returns | Description |
| --- | --- | --- |
| `randomBytes(int $length)` | `string` | Cryptographically secure random bytes. Throws `InvalidArgumentException` if `$length <= 0`. |
| `randomHex(int $bytes = 32)` | `string` | Hex-encoded random string (`2 * $bytes` characters). |
| `hashEquals(string $known, string $user)` | `bool` | Constant-time string comparison (`hash_equals`). |
| `hmacSha256(string $data, string $key, bool $raw = true)` | `string` | HMAC-SHA256 of `$data`. `$raw = true` returns raw binary, `false` returns hex. Throws `InvalidArgumentException` on empty key. |

```php
use System\Security\Crypto;

$token = Crypto::randomHex(32);                     // 64 hex chars
$sig   = Crypto::hmacSha256($payload, $secret, false);   // hex signature
$ok    = Crypto::hashEquals($expected, $provided);
```

::: warning
Always compare secrets/tokens with `Crypto::hashEquals()`, never `===` — a plain equality check is vulnerable to timing attacks.
:::

---

## LoginThrottle

`System\Security\Login\LoginThrottle`

Brute-force protection keyed by a hash of **IP + identifier** (e.g. email). It counts failures within a sliding window and blocks once a threshold is reached. Storage uses APCu when available, otherwise a filesystem fallback under the system temp directory, so throttling works even without APCu. Every failed attempt also incurs a randomized 0.8–1.5 s delay to slow automated attacks.

| Method | Returns | Description |
| --- | --- | --- |
| `isBlocked(string $ip, string $identifier, int $maxFails = 8, int $windowSeconds = 600)` | `bool` | Whether attempts for this IP+identifier have hit `$maxFails` within the window. Expired windows auto-reset. |
| `onFailure(string $ip, string $identifier, int $windowSeconds = 600)` | `void` | Record a failed attempt (adds the delay and increments the counter). |
| `onSuccess(string $ip, string $identifier)` | `void` | Clear all throttling state for this IP+identifier. |

```php
use System\Security\Login\LoginThrottle;

$ip    = $request->ip();
$email = normalize_email($request->input('email'));

if (LoginThrottle::isBlocked($ip, $email)) {
    abort(429, 'Too many attempts. Try again later.');
}

if (!Password::verify($password, $user->password_hash)) {
    LoginThrottle::onFailure($ip, $email);   // increments + delays
    return back()->withErrors(['Invalid credentials']);
}

LoginThrottle::onSuccess($ip, $email);       // clears the counter
SessionStore::loginSuccess($user->id, $user->email, $user->roles, $user->name);
```

::: warning Gotchas
- **`onFailure()` blocks for ~0.8–1.5 s** by design (an anti-bot delay). Do not call it in a tight loop or a latency-sensitive path other than an actual failed login.
- **Keying is IP + identifier.** A single attacker rotating IPs, or many users behind one NAT, can skew the count — pair it with other controls for high-value endpoints.
- The identifier is lowercased and trimmed before hashing, so `Alice@x.com` and `alice@x.com ` share a counter.
:::

## Related

- [Security Overview](/security/overview)
- [CSRF Protection](/security/csrf)
- [JWT](/security/jwt)
- [Crypto & Passwords](/security/crypto)
- [Authentication](/security/authentication)
- [SessionStore API Reference](/api/session-store)
- [Helpers API Reference](/api/helpers)
