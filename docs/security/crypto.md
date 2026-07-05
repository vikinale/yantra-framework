# Crypto & Passwords

Yantra wraps PHP's native cryptographic primitives in two small static classes: `System\Security\Password` for hashing and verifying user passwords, and `System\Security\Crypto` for random values, HMACs, and constant-time comparison. There is nothing exotic here by design — the value is safe defaults and impossible-to-misuse signatures.

```php
use System\Security\Password;
use System\Security\Crypto;

$hash  = Password::hash('my_password');
$valid = Password::verify('my_password', $hash);

$apiKey = Crypto::randomHex(32);   // 64-char hex string
```

## Password hashing

### `Password::hash(string $password, array $options = []): string`

Hashes with **Argon2id when the PHP build supports it, falling back to bcrypt** otherwise. Defaults are deliberately strong:

- **Argon2id:** `memory_cost` 128 MB (`1 << 17` KiB), `time_cost` 4, `threads` 2
- **bcrypt fallback:** `cost` 12

Anything you pass in `$options` overrides these. An empty password throws `InvalidArgumentException`; a hashing failure throws `RuntimeException`.

```php
$hash = Password::hash($password);

// Tune costs (e.g. lower memory on constrained hosts)
$hash = Password::hash($password, ['memory_cost' => 1 << 16, 'time_cost' => 3]);
```

### `Password::verify(string $password, string $hash): bool`

Constant-time verification via PHP's `password_verify()`. An empty hash returns `false` — no exception. The algorithm is read from the hash itself, so hashes created under bcrypt still verify after you upgrade to an Argon2id-capable PHP.

### `Password::needsRehash(string $hash, array $options = []): bool`

Returns `true` when the hash was created with a weaker algorithm or lower costs than the current defaults (plus your overrides). The standard upgrade-on-login pattern:

```php
if (Password::verify($password, $user->password_hash)) {
    if (Password::needsRehash($user->password_hash)) {
        $user->password_hash = Password::hash($password);
        $user->save();
    }
    // ... log the user in
}
```

## Crypto utilities

```php
use System\Security\Crypto;

$bytes = Crypto::randomBytes(32);            // raw binary, CSPRNG
$hex   = Crypto::randomHex(32);              // 64-char hex string (32 bytes)
$hmac  = Crypto::hmacSha256($data, $key);    // raw binary HMAC by default
$hex   = Crypto::hmacSha256($data, $key, raw: false);  // hex-encoded HMAC
$same  = Crypto::hashEquals($known, $user);  // constant-time comparison
```

- `randomBytes(int $length): string` — cryptographically secure random bytes (`random_bytes`). Throws `InvalidArgumentException` for lengths ≤ 0.
- `randomHex(int $bytes = 32): string` — hex-encoded random bytes; the result is **`2 × $bytes` characters** long.
- `hmacSha256(string $data, string $key, bool $raw = true): string` — HMAC-SHA256; raw binary by default, hex when `raw: false`. Throws `InvalidArgumentException` on an empty key.
- `hashEquals(string $known, string $user): bool` — constant-time string comparison (`hash_equals`); the known/expected value goes first.

## Choosing the right tool

| Need | Use |
|---|---|
| Store a user's password | `Password::hash()` — never `hmacSha256` or plain hashes; password hashing must be slow |
| Check a login attempt | `Password::verify()` |
| Silently upgrade old hashes | `Password::needsRehash()` on successful login |
| API keys, reset tokens, session identifiers | `Crypto::randomHex()` (or `randomBytes` when you need raw binary) |
| Sign/verify data you hand to clients (webhook payloads, signed URLs) | `Crypto::hmacSha256()` to sign, recompute + `Crypto::hashEquals()` to verify |
| Compare any secret against user input | `Crypto::hashEquals()` — never `===`, which leaks timing |

For encoding/decoding signed JSON tokens specifically, use the [JWT class](/security/jwt) rather than rolling your own HMAC scheme.

::: warning Gotchas
- `hmacSha256()` returns **raw binary by default** (`raw: true`). Pass `raw: false` if you're storing or transmitting the value as text — comparing a raw HMAC against a hex string will always fail.
- `hashEquals()` argument order matters for timing safety: the **known/expected** value first, the user-supplied value second.
- Argon2id's default `memory_cost` is 128 MB per hash. On small containers or high-concurrency login endpoints, budget for it or lower the cost explicitly.
- `Password::hash()` throws on an empty password — validate input before hashing (see [Validation](/essentials/validation)).
:::

## Related

- [Security Overview](/security/overview)
- [Authentication](/security/authentication)
- [JWT](/security/jwt)
- [CSRF Protection](/security/csrf)
- [Validation](/essentials/validation)
- [Webhooks](/features/webhooks)
