# 5. Authentication

In this step you'll add users, a session-based login backed by `Password::hash`/`verify`, brute-force protection with `LoginThrottle`, and a session auth guard that protects the write routes so only logged-in users can create, edit, or delete posts.

::: tip You'll need step 4 first
This continues from [4. Validation](/tutorial/04-validation). We'll add a `users` table and a `User` model (like the `posts` work in step 2), a login controller, and route protection over the `PostController` write actions.
:::

Yantra's auth is deliberately unopinionated: it gives you secure building blocks — password hashing, a hardened session store, login throttling, and a session guard — and you wire them together. The convention that ties it together is a single session key: an authenticated user has an `auth` array in the session with a non-empty `uid`.

## Users table & model

Create the migration:

```bash
php vendor/bin/yantra make:migration create_users_table
```

```php
<?php
declare(strict_types=1);

use System\Contracts\MigrationInterface;
use System\Database\Database;
use System\Database\Schema\Schema;
use System\Database\Schema\Blueprint;

return new class implements MigrationInterface {
    public function up(Database $db): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 100);
            $table->string('email', 255)->unique();
            $table->string('password_hash', 255);
            $table->timestamps();
        });
    }

    public function down(Database $db): void
    {
        Schema::dropIfExists('users');
    }
};
```

```bash
php vendor/bin/yantra migrate
```

Create `App/Models/User.php`. Note `password_hash` is **not** fillable — we always set it explicitly through `Password::hash()`, never from raw request input:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use System\Database\Model;

class User extends Model
{
    protected ?string $tableName = 'users';

    protected array $fillable = [
        'name',
        'email',
    ];
}
```

## Seed a user

Add a seeder so you have an account to log in with:

```bash
php vendor/bin/yantra make:seeder UsersTableSeeder
```

```php
<?php
declare(strict_types=1);

namespace Database\Seeders;

use System\Contracts\SeederInterface;
use System\Database\Database;
use System\Security\Password;

final class UsersTableSeeder implements SeederInterface
{
    public function run(Database $db): void
    {
        $stmt = $db->prepare(
            'INSERT INTO users (name, email, password_hash, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        );

        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'Admin',
            'admin@example.com',
            Password::hash('secret123'),
            $now,
            $now,
        ]);
    }
}
```

Wire it into the root seeder alongside the posts seeder and run it:

```php
// database/seeders/DatabaseSeeder.php — run()
(new UsersTableSeeder())->run($db);
(new PostsTableSeeder())->run($db);
```

```bash
php vendor/bin/yantra db:seed
```

`Password::hash()` prefers Argon2id (bcrypt fallback) with safe defaults; `Password::verify($plain, $hash)` checks a candidate against the stored hash.

## Login routes

Add these to `App/Routes/web.php`:

```php
$r->get('/login', 'AuthController@showLogin')->name('login');
$r->post('/login', 'AuthController@login')->name('login.attempt');
$r->post('/logout', 'AuthController@logout')->name('logout');
```

## The auth controller

Create `App/Controllers/AuthController.php`. The login flow is: validate CSRF → check the throttle → verify credentials (recording failures) → on success clear the throttle and establish the session via `SessionGuard::onLoginSuccess()`.

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use System\Core\BaseController;
use System\Http\Response;
use System\Security\Password;
use System\Security\Login\LoginThrottle;
use System\Security\Middleware\SessionGuard;
use System\Session\SessionStore;
use App\Models\User;

class AuthController extends BaseController
{
    // GET /login
    public function showLogin(): Response
    {
        return $this->render('auth.login');
    }

    // POST /login
    public function login(): Response
    {
        if (!$this->validateCsrf('default')) {
            return $this->error('Invalid CSRF token', 419);
        }

        $email    = (string) $this->req()->input('email', '');
        $password = (string) $this->req()->input('password', '');
        $ip       = (string) ($this->req()->ip() ?? '');

        // 1. Throttle brute force per IP + identifier
        if (LoginThrottle::isBlocked($ip, $email, maxFails: 5, windowSeconds: 300)) {
            SessionStore::setFlash('error', 'Too many attempts. Try again later.');
            return $this->redirectAfterPost('/login');
        }

        // 2. Look up the user (firstModel → object, or null)
        $user = (new User)->where('email', '=', $email)->firstModel();

        // 3. Verify credentials
        if ($user === null || !Password::verify($password, (string) $user->password_hash)) {
            LoginThrottle::onFailure($ip, $email);   // record + random 0.8–1.5s delay
            SessionStore::setFlash('error', 'Invalid credentials.');
            return $this->redirectAfterPost('/login');
        }

        // 4. Success: clear throttle, establish the session
        LoginThrottle::onSuccess($ip, $email);

        SessionGuard::onLoginSuccess($user->id, roles: [], data: [
            'name'  => $user->name,
            'email' => $user->email,
        ]);

        return $this->redirectAfterPost('/posts');
    }

    // POST /logout
    public function logout(): Response
    {
        if (!$this->validateCsrf('default')) {
            return $this->error('Invalid CSRF token', 419);
        }

        SessionGuard::logout();
        return $this->redirectAfterPost('/login');
    }
}
```

What the security pieces do:

- **`LoginThrottle`** limits guessing per **IP + identifier** pair (the identifier is lowercased, so `Admin@x.com` and `admin@x.com` share a bucket). `isBlocked()` returns `true` once failures reach the limit within the window; `onFailure()` increments the counter **and always sleeps 0.8–1.5s**; `onSuccess()` clears the state. Storage uses APCu when available, with a locked-file fallback, so it works out of the box.
- **`SessionGuard::onLoginSuccess($userId, roles: [...], data: [...])`** regenerates the session id (anti-fixation), then stores `uid` (as a string), the roles, an `iat` timestamp, your `data`, and a hijack **fingerprint** under the `auth` session key. `SessionGuard::logout()` clears it and regenerates again.

::: warning Gotchas
- `firstModel()` returns a `User` object (or `null`); `first()` would return a raw array. We need the object so `$user->password_hash` works.
- Always establish sessions through `SessionGuard::onLoginSuccess()` rather than writing `$_SESSION['auth']` yourself — you get session-id regeneration and the anti-hijack fingerprint for free.
- `onFailure()` blocks the worker for up to 1.5s by design. Call it only on genuine failures.
:::

## The login view

Create `views/auth/login.php`:

```php
<?php use System\View\View; ?>
<?php View::layout('layouts.app'); ?>

<?php View::section('title'); ?>Log in<?php View::endSection(); ?>

<?php View::section('content'); ?>
    <h1>Log in</h1>

    <?php $error = session('error'); ?>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="POST" action="/login">
        <?= csrf_field() ?>
        <p>
            <label>Email<br>
                <input type="email" name="email" value="<?= e(old('email')) ?>">
            </label>
        </p>
        <p>
            <label>Password<br>
                <input type="password" name="password">
            </label>
        </p>
        <button type="submit">Log in</button>
    </form>
<?php View::endSection(); ?>
```

## Read auth state in views

Two global helpers read the session's auth state anywhere:

```php
if (auth_check()) {
    $user = auth_user();          // ['uid' => '1', 'name' => 'Admin', 'email' => ..., 'roles' => [], ...]
    echo e($user['name'] ?? '');
}
```

Update the header in `views/layouts/app.php` to show a logout form (which needs its own CSRF token) when logged in:

```php
<header>
    <a href="/">Home</a> ·
    <a href="/posts">Posts</a>
    <?php if (auth_check()): ?>
        · <a href="/posts/create">New post</a>
        · <form method="POST" action="/logout" style="display:inline">
              <?= csrf_field() ?>
              <button type="submit">Log out (<?= e(auth_user()['name'] ?? '') ?>)</button>
          </form>
    <?php else: ?>
        · <a href="/login">Log in</a>
    <?php endif; ?>
</header>
```

`auth_user()` returns whatever you stored at login — it's session data, not a fresh database row. Re-fetch the model when you need current data.

## Protect the write routes

`AuthGuardMiddleware` requires a logged-in user (a session `auth` array with a non-empty `uid`); with a `redirect` parameter it sends anonymous visitors to `/login` instead of returning a bare 401. It ships **without a built-in alias**, so register one in the root `config/middleware.php`:

```php
<?php
// config/middleware.php
return [
    'aliases' => [
        'auth'  => System\Security\Middleware\AuthGuardMiddleware::class,
        'guest' => System\Security\Middleware\GuestOnlyMiddleware::class,
    ],
];
```

Now apply `auth` to the routes that mutate posts. The read routes (`index`, `show`) stay public; `create`, `store`, `edit`, `update`, and `destroy` require login:

```php
<?php
// App/Routes/web.php
declare(strict_types=1);

use System\Core\Routing\RouteCollector;

return function (RouteCollector $r) {
    $r->get('/', 'HomeController@index')->name('home');

    // Public reads
    $r->get('/posts', 'PostController@index')->name('posts.index');
    $r->get('/posts/{id}', 'PostController@show')->name('posts.show');

    // Auth
    $r->get('/login', 'AuthController@showLogin')->name('login');
    $r->post('/login', 'AuthController@login')->name('login.attempt');
    $r->post('/logout', 'AuthController@logout')->name('logout');

    // Protected writes — redirect anonymous users to /login
    $r->group('', ['middleware' => ['auth']], function (RouteCollector $r) {
        $r->get('/posts/create', 'PostController@create')->name('posts.create');
        $r->post('/posts', 'PostController@store')->name('posts.store');
        $r->get('/posts/{id}/edit', 'PostController@edit')->name('posts.edit');
        $r->post('/posts/{id}', 'PostController@update')->name('posts.update');
        $r->post('/posts/{id}/delete', 'PostController@destroy')->name('posts.destroy');
    })->middleware('auth', ['redirect' => '/login']);
};
```

::: warning Gotchas
- `AuthGuardMiddleware`/`GuestOnlyMiddleware` have **no built-in aliases** — `'auth'`/`'guest'` are conventions you register yourself (as above), unlike the framework's built-in security aliases (`sec.csrf`, `rate.limit`, etc.).
- Keep `/posts/create` inside the protected group but still **before** `/posts/{id}` in overall ordering, so `create` isn't matched as an id.
- The session fingerprint binds the login to the user agent and IP `/24` prefix; users on aggressively rotating mobile IPs may occasionally be logged out.
:::

## Try it

```bash
php -S localhost:8000 -t public
```

Log out (or open a private window) and visit `/posts/create` — you're bounced to `/login`. Sign in with `admin@example.com` / `secret123` and you're back in business. Enter a wrong password a few times to watch the throttle kick in.

## Next

The blog is a full server-rendered app. Next we expose the posts as a JSON API secured with a JWT.

→ **[6. JSON API & JWT](/tutorial/06-api)**
← [4. Validation](/tutorial/04-validation)

## Related

- [Authentication](/security/authentication)
- [Crypto & Passwords](/security/crypto)
- [Session & Cookies](/essentials/session)
- [Middleware](/essentials/middleware)
