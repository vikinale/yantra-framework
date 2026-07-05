# JWT

Yantra includes a dependency-free JWT implementation in `System\Security\Jwt\Jwt` for issuing and verifying **HS256** (HMAC-SHA256) tokens, plus a `JwtAuthMiddleware` (alias `auth.jwt`) that authenticates API requests — the middleware verifies **RS256** tokens against a configured public key. Signature checks use constant-time comparison, and standard time claims (`nbf`, `iat`, `exp`) are validated with configurable leeway.

```php
use System\Security\Jwt\Jwt;

$token = Jwt::encodeHS256([
    'sub'   => $user->id,
    'roles' => ['user'],
    'iat'   => time(),
    'exp'   => time() + 3600,
], $secretKey);

$payload = Jwt::decodeHS256($token, $secretKey);   // array, or null if invalid
```

## Encoding

`Jwt::encodeHS256(array $payload, string $secret, array $header = []): string` builds a compact JWT signed with HMAC-SHA256. The header defaults to `{"typ":"JWT","alg":"HS256"}`; anything you pass in `$header` is merged over it. An empty secret or a JSON-encoding failure throws `JwtException`.

```php
$token = Jwt::encodeHS256(
    ['sub' => 42, 'exp' => time() + 900],
    $secret,
    header: ['kid' => 'key-2026-01']   // optional extra header fields
);
```

## Decoding & verification

`Jwt::decodeHS256(string $jwt, string $secret, int $leewaySeconds = 0, bool $throw = false): ?array` verifies and returns the payload array, or `null` on any failure. Pass `throw: true` to get a `JwtException` with the failure reason instead:

```php
use System\Security\Jwt\Jwt;
use System\Security\Jwt\JwtException;

// Quiet mode: null on failure
$payload = Jwt::decodeHS256($token, $secret);
if ($payload === null) {
    return $this->error('Invalid token', 401);
}

// Throwing mode with clock-skew leeway
try {
    $payload = Jwt::decodeHS256($token, $secret, leewaySeconds: 30, throw: true);
} catch (JwtException $e) {
    // e.g. "Token expired (exp)." / "Signature verification failed."
}
```

Verification rejects tokens that: don't have exactly 3 parts, contain invalid base64url or JSON, declare any `alg` other than `HS256` (blocking `alg: none` and algorithm-confusion attacks), or fail the constant-time signature check.

### Claim validation

After the signature passes, the standard time claims are checked (each only if present and numeric):

| Claim | Rule | Failure |
|---|---|---|
| `nbf` | `now + leeway` must be ≥ `nbf` | "Token not active yet (nbf)." |
| `iat` | `iat` must be ≤ `now + leeway` | "Token issued in the future (iat)." |
| `exp` | `now - leeway` must be < `exp` | "Token expired (exp)." |

`JwtException` extends `RuntimeException`.

## Issuing tokens from a login endpoint

```php
<?php
namespace App\Controllers\Api;

use System\Controller;
use System\Security\Jwt\Jwt;
use System\Security\Password;
use App\Models\User;

class TokenController extends Controller
{
    public function issue()
    {
        $email    = (string) $this->request->input('email');
        $password = (string) $this->request->input('password');

        $user = User::where('email', $email)->first();
        if (!$user || !Password::verify($password, $user->password_hash)) {
            return $this->error('Invalid credentials', 401);
        }

        $now   = time();
        $token = Jwt::encodeHS256([
            'sub'   => (string) $user->id,
            'roles' => $user->roles ?? [],
            'iat'   => $now,
            'nbf'   => $now,
            'exp'   => $now + 3600,        // 1 hour
        ], (string) env('JWT_SECRET'));

        return $this->json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }
}
```

Clients then send `Authorization: Bearer <token>` on API requests. See the [JWT API cookbook](/cookbook/jwt-api) for a complete walkthrough.

## JwtAuthMiddleware (`auth.jwt`)

`System\Security\Middleware\JwtAuthMiddleware` protects routes with bearer-token authentication. Important: **it verifies RS256 signatures** (via OpenSSL and a configured public key), not HS256. On each request it:

1. Extracts the token from the `Authorization: Bearer ...` header — missing token → **401** "Missing Bearer token."
2. Verifies the RS256 signature against the configured public key; the header's `alg` must be exactly `RS256` — failure → **401** "Invalid token."
3. Checks `nbf` and `exp` with the configured leeway (default 30 s) → **401** "Token not active yet." / "Token expired."
4. If required roles are configured, the payload's `roles` (or `role`) claim must contain **at least one** of them → otherwise **403** "Forbidden (missing role)."
5. On success, attaches the payload to the request under `auth.jwt` (when the Request supports attribute storage), so controllers can read the verified identity.

```php
$r->group('/api', ['middleware' => ['auth.jwt', 'rate.limit']], function ($r) {
    $r->get('/me', 'Api\ProfileController@show');
});
```

### Configuration (`security.jwt.*`)

The container builds the middleware from config (with environment-variable fallbacks). It **throws at boot** if no public key can be resolved:

| Config key | Fallback env var | Purpose |
|---|---|---|
| `security.jwt.public_key_pem` | `JWT_PUBLIC_KEY_PEM` | RSA public key as an inline PEM string |
| `security.jwt.public_key_path` | `JWT_PUBLIC_KEY_PATH` | Path to a PEM file (used when no inline PEM is set) |
| `security.jwt.required_roles` | — | Array of roles; token needs at least one (default `[]`) |
| `security.jwt.leeway_seconds` | — | Clock-skew allowance for `nbf`/`exp` (default `30`) |

```php
// App/Config/security.php
return [
    'jwt' => [
        'public_key_path' => '/etc/keys/jwt-public.pem',
        'required_roles'  => [],       // e.g. ['api']
        'leeway_seconds'  => 30,
    ],
];
```

::: warning Gotchas
- There are **no `Jwt::encode()`, `Jwt::decode()`, or `Jwt::verify()` methods** — the class exposes exactly `encodeHS256()` and `decodeHS256()`.
- **Algorithm mismatch by design:** the `Jwt` class signs/verifies HS256, while `JwtAuthMiddleware` verifies RS256 only. Tokens made with `Jwt::encodeHS256()` will be rejected by the middleware. Either issue RS256 tokens (signed externally or with OpenSSL) for middleware-protected routes, or verify HS256 tokens manually with `Jwt::decodeHS256()` in your own middleware/controller.
- `decodeHS256()` returns `null` on failure by default — an easy silent bug if you forget the null check. Prefer `throw: true` in development.
- `exp`, `nbf`, and `iat` are only validated **when present**. Always set `exp` when issuing tokens; nothing forces a token to expire otherwise.
:::

## Related

- [Security Overview](/security/overview)
- [Authentication](/security/authentication)
- [Crypto & Passwords](/security/crypto)
- [JWT API cookbook](/cookbook/jwt-api)
- [Rate Limiting cookbook](/cookbook/rate-limiting)
- [Middleware](/essentials/middleware)
