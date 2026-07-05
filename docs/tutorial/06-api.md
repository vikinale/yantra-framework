# 6. JSON API & JWT

In this step you'll expose the blog's posts as a JSON API under `/api`, issue a stateless JWT from a token endpoint with `Jwt::encodeHS256`, and protect the write endpoints by verifying that token manually with `Jwt::decodeHS256`.

::: tip You'll need step 5 first
This continues from [5. Authentication](/tutorial/05-auth) — the `users` table, the `User` model, and `Password`. The API reuses them for token issuance. Make sure `JWT_SECRET` is set in your `.env` (from step 1).
:::

::: warning Why manual verification?
Yantra's built-in `JwtAuthMiddleware` (alias `auth.jwt`) verifies **RS256** tokens against a configured public key — not the **HS256** tokens that `Jwt::encodeHS256()` produces. Rather than set up an RSA keypair, this tutorial issues HS256 tokens and verifies them ourselves with `Jwt::decodeHS256()` inside a small custom middleware. The `Jwt` class exposes exactly two methods: `encodeHS256()` and `decodeHS256()` — there is no `encode()`/`decode()`/`verify()`.
:::

## API routes

Routes in `App/Routes/api.php` are **automatically prefixed with `/api`** — don't add the prefix yourself. Define public reads plus token issuance, and a protected group for writes:

```php
<?php
// App/Routes/api.php
declare(strict_types=1);

use System\Core\Routing\RouteCollector;

return function (RouteCollector $r) {
    // Issue a token: POST /api/token
    $r->post('/token', 'Api\TokenController@issue')->name('api.token');

    // Public reads
    $r->get('/posts', 'Api\PostController@index')->name('api.posts.index');       // → /api/posts
    $r->get('/posts/{id}', 'Api\PostController@show')->name('api.posts.show');     // → /api/posts/{id}

    // Protected writes — our custom middleware alias (registered below)
    $r->group('', ['middleware' => ['jwt']], function (RouteCollector $r) {
        $r->post('/posts', 'Api\PostController@store')->name('api.posts.store');
        $r->delete('/posts/{id}', 'Api\PostController@destroy')->name('api.posts.destroy');
    });
};
```

Because these are JSON APIs consumed by non-browser clients, we don't use CSRF here — the bearer token is the credential.

## Issue a token

Create `App/Controllers/Api/TokenController.php`. It verifies email + password (reusing the users from step 5) and returns a signed HS256 token:

```php
<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use System\Core\BaseController;
use System\Http\Response;
use System\Security\Password;
use System\Security\Jwt\Jwt;
use App\Models\User;

class TokenController extends BaseController
{
    // POST /api/token
    public function issue(): Response
    {
        $email    = (string) $this->req()->input('email', '');
        $password = (string) $this->req()->input('password', '');

        $user = (new User)->where('email', '=', $email)->firstModel();

        if ($user === null || !Password::verify($password, (string) $user->password_hash)) {
            return $this->error('Invalid credentials', 401);
        }

        $now = time();
        $token = Jwt::encodeHS256([
            'sub'   => (string) $user->id,
            'name'  => $user->name,
            'iat'   => $now,
            'nbf'   => $now,
            'exp'   => $now + 3600,          // 1 hour
        ], (string) env('JWT_SECRET'));

        return $this->success([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }
}
```

`$this->success($data)` wraps the payload in the standard envelope `{"status":"success","data":{...}}`; `$this->error($msg, $status)` produces `{"status":"error","message":"..."}`. `encodeHS256()` throws `JwtException` if the secret is empty, so keep `JWT_SECRET` populated.

## A manual JWT middleware

Create `App/Middleware/JwtHs256Middleware.php`. It pulls the bearer token from the `Authorization` header, verifies it with `Jwt::decodeHS256()`, and either passes the request through (attaching the payload) or short-circuits with a 401.

Yantra middleware are invokable classes: they implement `__invoke(Request $req, Response $res, callable $next, array $params)` and receive the request, response, a `$next` callable, and any route-level params array. The shape below is the common one:

```php
<?php
declare(strict_types=1);

namespace App\Middleware;

use System\Http\Request;
use System\Http\Response;
use System\Security\Jwt\Jwt;

class JwtHs256Middleware
{
    public function __invoke(Request $request, Response $response, callable $next, array $params = []): Response
    {
        $header = (string) ($request->header('Authorization') ?? '');

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $response->json(
                ['status' => 'error', 'message' => 'Missing Bearer token'],
                401
            );
        }

        // Quiet mode returns null on any failure (bad signature, expired, wrong alg…)
        $payload = Jwt::decodeHS256($m[1], (string) env('JWT_SECRET'));

        if ($payload === null) {
            return $response->json(
                ['status' => 'error', 'message' => 'Invalid or expired token'],
                401
            );
        }

        // Make the verified identity available to controllers
        $request->set('auth.jwt', $payload);

        return $next($request, $response);
    }
}
```

`decodeHS256()` rejects tokens that don't have three parts, use any `alg` other than `HS256` (blocking `alg: none` and algorithm-confusion attacks), fail the constant-time signature check, or violate `nbf`/`iat`/`exp`. Pass `throw: true` during development to get a `JwtException` with the specific reason instead of a silent `null`.

Register the alias in the root `config/middleware.php` (alongside the `auth`/`guest` aliases from step 5):

```php
<?php
// config/middleware.php
return [
    'aliases' => [
        'auth' => System\Security\Middleware\AuthGuardMiddleware::class,
        'guest' => System\Security\Middleware\GuestOnlyMiddleware::class,
        'jwt'  => App\Middleware\JwtHs256Middleware::class,   // our HS256 verifier
    ],
];
```

::: warning Gotchas
- **Don't use the built-in `auth.jwt` alias here** — it verifies RS256 only and will reject the HS256 tokens we issue. That's why we register our own `jwt` alias.
- `decodeHS256()` returns `null` on failure by default — always null-check it (as above), or use `throw: true`. Silent nulls are an easy security bug.
- `exp`/`nbf`/`iat` are only validated **when present**. We always set `exp` when issuing, so tokens actually expire.
- All app middleware implement `__invoke(Request $req, Response $res, callable $next, array $params = [])` — there is no `handle()` contract. Confirm the signature against an existing middleware class or [Middleware](/essentials/middleware).
:::

## The API post controller

Create `App/Controllers/Api/PostController.php`. Reads return raw arrays (fine for JSON); writes read the verified identity from the request attribute the middleware set:

```php
<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use System\Core\BaseController;
use System\Http\Response;
use System\Validation\Validator;
use App\Models\Post;

class PostController extends BaseController
{
    // GET /api/posts
    public function index(): Response
    {
        $posts = Post::query()
            ->orderBy('created_at', 'DESC')
            ->get();                       // raw arrays serialize cleanly to JSON

        return $this->success($posts);
    }

    // GET /api/posts/{id}
    public function show(int $id): Response
    {
        $post = (new Post)->find($id);     // raw array or null

        if ($post === null) {
            return $this->error('Post not found', 404);
        }

        return $this->success($post);
    }

    // POST /api/posts   (protected by the jwt middleware)
    public function store(): Response
    {
        $input = $this->req()->only(['title', 'slug', 'body']);

        $validator = new Validator($input, [
            'title' => 'required|string|max:200',
            'slug'  => 'required|slug|max:200',
            'body'  => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->all());   // HTTP 422
        }

        $id = (new Post)->create($input + ['published' => true]);

        // The middleware attached the verified token payload
        $jwt = $this->req()->attr('auth.jwt');

        return $this->success([
            'id'        => $id,
            'author_id' => $jwt['sub'] ?? null,
        ], 201);
    }

    // DELETE /api/posts/{id}   (protected)
    public function destroy(int $id): Response
    {
        $count = (new Post)->deleteById($id);

        if ($count === 0) {
            return $this->error('Post not found', 404);
        }

        return $this->success(['deleted' => $id]);
    }
}
```

For JSON validation failures, `validationError()` returns HTTP 422 with the messages — the same rules you wrote in step 4, reused verbatim.

## Try it

```bash
php -S localhost:8000 -t public
```

Fetch the public list, then get a token and use it to create a post:

```bash
# Public read
curl http://localhost:8000/api/posts

# Get a token
curl -X POST http://localhost:8000/api/token \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"secret123"}'

# Use the token (paste the value from the response above)
curl -X POST http://localhost:8000/api/posts \
  -H 'Authorization: Bearer <TOKEN>' \
  -H 'Content-Type: application/json' \
  -d '{"title":"From the API","slug":"from-the-api","body":"Created over HTTP."}'
```

Omitting the `Authorization` header (or sending a tampered token) returns `401`.

## Next

The blog now has a web UI and a JSON API. Let's lock in the behavior with tests.

→ **[7. Testing](/tutorial/07-testing)**
← [5. Authentication](/tutorial/05-auth)

## Related

- [JWT](/security/jwt)
- [Routing](/essentials/routing) — API routes & groups
- [Middleware](/essentials/middleware)
- [Controllers](/essentials/controllers) — JSON envelope helpers
