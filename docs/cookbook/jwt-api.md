# Building a JWT API

This recipe issues a signed token from a login endpoint and then protects other routes by verifying it. Yantra's `System\Security\Jwt\Jwt` signs and verifies **HS256** (HMAC-SHA256) tokens with a shared secret — `encodeHS256()` to issue, `decodeHS256()` to verify.

```php
use System\Security\Jwt\Jwt;

// Issue
$token = Jwt::encodeHS256(['sub' => $user->id, 'exp' => time() + 3600], $secret);

// Verify
$payload = Jwt::decodeHS256($token, $secret);   // array, or null if invalid
```

::: warning Read this before choosing an approach
The built-in `JwtAuthMiddleware` (alias `auth.jwt`) verifies **RS256** only, against a configured RSA public key — it will **reject** the HS256 tokens produced by `Jwt::encodeHS256()`. So for a shared-secret (HS256) flow, verify tokens yourself with `Jwt::decodeHS256()` in a small custom middleware or controller check, as shown below. If you want to use the built-in middleware, issue RS256 tokens instead (signed externally or with OpenSSL) — see the [JWT reference](/security/jwt).
:::

## 1. Issue a token from a login endpoint

`Jwt::encodeHS256(array $payload, string $secret, array $header = []): string`. Put whatever claims you need in the payload; set `exp` so the token actually expires, and `iat`/`nbf` if you want issued-at / not-before checks.

```php
<?php
namespace App\Controllers\Api;

use System\Controller;
use System\Http\Response;
use System\Security\Jwt\Jwt;
use System\Security\Password;
use App\Models\User;

class TokenController extends Controller
{
    public function issue(): Response
    {
        $email    = (string) $this->request->input('email');
        $password = (string) $this->request->input('password');

        $user = User::where('email', '=', $email)->firstModel();
        if (!$user || !Password::verify($password, $user->password_hash)) {
            return $this->error('Invalid credentials', 401);
        }

        $now   = time();
        $token = Jwt::encodeHS256([
            'sub'   => (string) $user->id,
            'roles' => $user->roles ?? [],
            'iat'   => $now,
            'nbf'   => $now,
            'exp'   => $now + 3600,          // 1 hour
        ], (string) env('JWT_SECRET'));

        return $this->json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }
}
```

An empty secret or a JSON-encoding failure throws `JwtException`.

## 2. Verify the token on protected routes

`Jwt::decodeHS256(string $jwt, string $secret, int $leewaySeconds = 0, bool $throw = false): ?array` returns the payload array on success or `null` on any failure (bad signature, wrong `alg`, expired, malformed). Verification rejects any token whose `alg` is not exactly `HS256`, blocking `alg: none` and algorithm-confusion attacks.

### A custom HS256 middleware

Middleware in Yantra is any class with an `__invoke(Request, Response, callable $next, array $params)` signature. Pull the bearer token off the `Authorization` header, verify it, and either attach the payload to the request or short-circuit with a 401.

```php
<?php
namespace App\Middleware;

use System\Http\Request;
use System\Http\Response;
use System\Http\ApiResponse;
use System\Security\Jwt\Jwt;

class Hs256AuthMiddleware
{
    public function __invoke(Request $req, Response $res, callable $next, array $params = []): Response
    {
        $header = (string) $req->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return ApiResponse::createError($res, 'Missing Bearer token.', 401);
        }

        $token   = trim(substr($header, 7));
        $payload = Jwt::decodeHS256($token, (string) env('JWT_SECRET'), leewaySeconds: 30);

        if ($payload === null) {
            return ApiResponse::createError($res, 'Invalid token.', 401);
        }

        // Hand the verified identity to downstream controllers.
        $req->set('auth.user', $payload);

        return $next($req, $res);
    }
}
```

Register it as an alias in `config/middleware.php` and apply it to routes:

```php
// config/middleware.php
'aliases' => [
    'auth.hs256' => App\Middleware\Hs256AuthMiddleware::class,
],
```

```php
$r->group('/api', ['middleware' => ['auth.hs256']], function ($r) {
    $r->get('/me', 'Api\ProfileController@show');
});
```

The controller then reads the verified payload from the request attribute:

```php
public function show(): Response
{
    $claims = $this->req()->attr('auth.user');   // the decoded payload array
    return $this->json(['user_id' => $claims['sub'] ?? null]);
}
```

### Or verify inline in a controller

If you only guard one action, skip the middleware and decode directly. Use `throw: true` to get a `JwtException` with a specific reason instead of a silent `null`:

```php
use System\Security\Jwt\Jwt;
use System\Security\Jwt\JwtException;

$token = trim(substr((string) $this->req()->header('Authorization', ''), 7));

try {
    $payload = Jwt::decodeHS256($token, (string) env('JWT_SECRET'), leewaySeconds: 30, throw: true);
} catch (JwtException $e) {
    return $this->error('Unauthorized: ' . $e->getMessage(), 401);
}
```

## Using the RS256 middleware instead (asymmetric keys)

If you need asymmetric signing — the issuer holds a private key, verifiers only need the public key — use the built-in `auth.jwt` middleware, which verifies RS256. You must configure a public key (it throws at boot otherwise):

```php
$r->group('/api', ['middleware' => ['auth.jwt']], function ($r) {
    $r->get('/me', 'Api\ProfileController@show');
});
```

Configuration and the exact behavior (roles, leeway, 401/403 responses) are covered in the [JWT reference](/security/jwt). Remember: RS256 tokens are **not** produced by `Jwt::encodeHS256()` — you sign those with an RSA private key outside this class.

::: warning Gotchas
- **`Jwt` is HS256-only; `auth.jwt` is RS256-only.** They do not interoperate. Pick one signing scheme per API and don't mix `encodeHS256()` tokens with `auth.jwt`.
- **`decodeHS256()` returns `null` on failure by default** — forget the null check and an invalid token silently reads as "no claims". Use `throw: true` (and catch `JwtException`) when you want the reason.
- **The class exposes only `encodeHS256()` and `decodeHS256()`** — there is no `Jwt::encode()`, `decode()`, or `verify()`.
- **Set `exp`.** `exp`, `nbf`, and `iat` are validated only when present, so a token with no `exp` never expires.
- **Keep `JWT_SECRET` long and secret.** HS256 security rests entirely on that shared key; an empty secret throws.
:::

## Related

- [JWT](/security/jwt) — the full `Jwt` and `JwtAuthMiddleware` reference
- [Middleware](/essentials/middleware) — registering and applying middleware
- [Rate limiting cookbook](/cookbook/rate-limiting) — pair with `rate.limit` to protect token endpoints
- [Requests](/essentials/requests) — reading headers and request attributes
