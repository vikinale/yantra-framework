# Middleware

Middleware wraps request handling in layers: each middleware can inspect or modify the request before the controller runs, and the response after. A middleware is any class (or callable) with an `__invoke(Request, Response, callable $next, array $params)` signature — no interface or base class required. Middleware runs globally on every request, on route groups, or on individual routes.

```php
class LogRequestMiddleware
{
    public function __invoke(Request $req, Response $res, callable $next, array $params = []): Response
    {
        // before controller
        $response = $next($req, $res);
        // after controller
        return $response;
    }
}
```

## Built-in Middleware Aliases

Exactly these eight aliases are registered out of the box:

| Alias | Class | Purpose |
|-------|-------|---------|
| `sec.normalize` | RequestNormalizationMiddleware | Validate HTTP method and body size |
| `sec.headers` | SecurityHeadersMiddleware | X-Content-Type-Options, Referrer-Policy, etc. |
| `sec.cookies` | CookieHardeningMiddleware | HttpOnly, Secure, SameSite on all cookies |
| `sec.csrf` | CsrfMiddleware | CSRF token validation on POST/PUT/PATCH/DELETE |
| `sec.audit` | AuditMiddleware | Request audit logging |
| `sec.csp` | CspNonceMiddleware | Content Security Policy nonces |
| `auth.jwt` | JwtAuthMiddleware | JWT bearer token authentication |
| `rate.limit` | RateLimitMiddleware | Request rate limiting |

Additional middleware classes ship with the framework but have **no built-in alias** — reference them by fully-qualified class name, or register your own alias (see [Registering Middleware](#registering-middleware) below):

| Class | Purpose |
|-------|---------|
| `System\Security\Middleware\AuthGuardMiddleware` | Session-based authentication |
| `System\Security\Middleware\GuestOnlyMiddleware` | Redirect authenticated users |
| `System\Security\Middleware\CorsMiddleware` | CORS header handling |

## Creating Custom Middleware

Generate a middleware class with the CLI:

```bash
php yantra make:middleware LogRequestMiddleware
```

Implement `__invoke()`. Call `$next($req, $res)` to pass control down the chain; anything before that call runs pre-controller, anything after runs post-controller. Parameters attached at the route via the `middleware($alias, $params)` array (e.g. `->middleware('auth', ['roles' => 'admin'])`) arrive in `$params`.

```php
<?php
namespace App\Middleware;

use System\Http\Request;
use System\Http\Response;

class LogRequestMiddleware
{
    public function __invoke(Request $req, Response $res, callable $next, array $params = []): Response
    {
        // Before: run logic before the controller
        $start = microtime(true);

        // Pass to next middleware / controller
        $response = $next($req, $res);

        // After: run logic after the controller
        $duration = round((microtime(true) - $start) * 1000, 2);
        error_log("Request: {$req->method()} {$req->path()} — {$duration}ms");

        return $response;
    }
}
```

To short-circuit the pipeline (e.g. reject an unauthenticated request), return a `Response` without calling `$next`.

## Registering Middleware

Middleware configuration comes from **two places**:

- **Global middleware** is read from the `middleware` config (`App/Config/middleware.php`), key `global`.
- **Groups and aliases** are loaded from `config/middleware.php` in the **project root** (by the Kernel's middleware config loader).

```php
// App/Config/middleware.php — global middleware (runs on every request)
return [
    'global' => [
        'sec.normalize',
        'sec.headers',
        'sec.cookies',
        'sec.csrf',
    ],
];
```

```php
// config/middleware.php — groups and aliases
return [
    // Middleware groups (a group name expands to all middleware in it)
    'groups' => [
        'web' => [
            'sec.csrf',
            App\Middleware\SessionMiddleware::class,
        ],
        'api' => [
            'auth.jwt',
            'rate.limit',
        ],
    ],

    // Middleware aliases (short names for route definitions)
    'aliases' => [
        'auth'  => System\Security\Middleware\AuthGuardMiddleware::class,
        'guest' => System\Security\Middleware\GuestOnlyMiddleware::class,
        'cors'  => System\Security\Middleware\CorsMiddleware::class,
        'log'   => App\Middleware\LogRequestMiddleware::class,
    ],
];
```

## Applying Middleware to Routes

Use aliases, group names, or FQCNs in route definitions:

```php
$r->get('/profile', 'ProfileController@show')->middleware('auth');

$r->group('/api', ['middleware' => ['auth.jwt', 'rate.limit']], function ($r) {
    // ...
});

// Parameters go in the second argument as an associative array
$r->post('/api/data', 'ApiController@store')
  ->middleware('auth', ['roles' => 'admin', 'redirect' => '/login']);
```

::: warning Gotchas
- `auth`, `guest`, and `cors` are **not** built-in aliases. Using them without defining them in `config/middleware.php` `aliases` will fail — only the eight `sec.*` / `auth.jwt` / `rate.limit` aliases exist out of the box.
- Note the two different files: `App/Config/middleware.php` holds the `global` stack, while the project-root `config/middleware.php` holds `groups` and `aliases`. Putting aliases in the wrong file means they silently never register.
:::

## Related

- [Routing](/essentials/routing)
- [Security Overview](/security/overview)
- [CSRF Protection](/security/csrf)
- [JWT](/security/jwt)
- [Rate Limiting Cookbook](/cookbook/rate-limiting)
