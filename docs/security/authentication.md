# Authentication

Yantra's session-based authentication is deliberately unopinionated: the framework gives you the secure building blocks — password hashing, a hardened session store, login throttling, fingerprinted session guards, and auth middleware — and you wire them together in a controller. The convention that ties everything together is a single session key: authenticated users have an `auth` array in the session with at least a non-empty `uid`.

```php
if (auth_check()) {
    $user = auth_user();   // ['uid' => '42', 'roles' => ['admin'], 'iat' => ..., ...]
    echo $user['name'] ?? '';
}
```

## Auth helpers

Two global helpers read the session's auth state:

- `auth_user(): ?array` — returns the array stored under the session key `auth` (via `SessionStore::get('auth')`), or `null` if it is missing, empty, or not an array.
- `auth_check(): bool` — simply `auth_user() !== null`.

Whatever you store in `auth` at login time is what `auth_user()` returns — the framework does not fetch the user from the database for you.

## SessionGuard

`System\Security\Middleware\SessionGuard` is a static helper that manages the auth session lifecycle safely:

```php
use System\Security\Middleware\SessionGuard;

// After verifying credentials against the database:
SessionGuard::onLoginSuccess($user->id, roles: ['admin'], data: ['name' => $user->name]);

// On logout:
SessionGuard::logout();
```

- `onLoginSuccess(int|string $userId, array $roles = [], ?array $data = [])` regenerates the session ID (anti session-fixation), then stores your `$data` merged with `uid` (cast to string), normalized `roles`, and an `iat` timestamp under the `auth` session key. It also stores a **session fingerprint** under `auth.fp` — a SHA-256 of the user agent plus the client IP truncated to a `/24` prefix (so NAT'd users don't get logged out).
- `logout()` removes `auth` and the fingerprint, then regenerates the session ID again.
- `validateFingerprint(): bool` compares the stored fingerprint against the current request; a mismatch suggests a hijacked session. It never blocks anonymous users.

## Complete login/logout controller

```php
<?php
namespace App\Controllers;

use System\Controller;
use System\Security\Password;
use System\Security\Login\LoginThrottle;
use System\Security\Middleware\SessionGuard;
use App\Models\User;

class AuthController extends Controller
{
    public function login()
    {
        $email    = (string) $this->request->input('email');
        $password = (string) $this->request->input('password');
        $ip       = (string) ($this->request->ip() ?? '');

        // 1. CSRF (if not already handled by sec.csrf middleware)
        if (!$this->validateCsrf('login')) {
            return $this->error('Invalid CSRF token', 403);
        }

        // 2. Throttle brute force per IP + identifier
        if (LoginThrottle::isBlocked($ip, $email)) {
            return $this->error('Too many login attempts. Try again later.', 429);
        }

        // 3. Verify credentials
        $user = User::where('email', $email)->first();

        if (!$user || !Password::verify($password, $user->password_hash)) {
            LoginThrottle::onFailure($ip, $email); // counts the failure + random delay
            return $this->error('Invalid credentials', 401);
        }

        // 4. Success: clear throttle, establish the session
        LoginThrottle::onSuccess($ip, $email);

        SessionGuard::onLoginSuccess($user->id, roles: $user->roles ?? [], data: [
            'name'  => $user->name,
            'email' => $user->email,
        ]);

        return $this->redirect('/dashboard');
    }

    public function logout()
    {
        SessionGuard::logout();
        return $this->redirect('/login');
    }
}
```

## Login throttling

`System\Security\Login\LoginThrottle` limits credential-guessing per **IP + identifier** pair (the identifier is lowercased, so `User@x.com` and `user@x.com` share a bucket):

```php
use System\Security\Login\LoginThrottle;

// Default: blocked after 8 failures within a 600-second window
if (LoginThrottle::isBlocked($ip, $email, maxFails: 5, windowSeconds: 300)) {
    return $this->error('Too many attempts', 429);
}

LoginThrottle::onFailure($ip, $email);  // record a failed attempt
LoginThrottle::onSuccess($ip, $email);  // clear state after a good login
```

- `isBlocked(string $ip, string $identifier, int $maxFails = 8, int $windowSeconds = 600): bool` — returns `true` once the fail count reaches `maxFails` within the window; an expired window resets automatically.
- `onFailure(string $ip, string $identifier, int $windowSeconds = 600): void` — increments the counter **and always sleeps for a random 0.8–1.5 seconds**, slowing bots even before any block kicks in.
- `onSuccess(string $ip, string $identifier): void` — deletes the throttle state.

Storage uses **APCu when available, with a filesystem fallback** (locked JSON files under the system temp directory), so throttling works out of the box on any host.

## AuthGuardMiddleware

`System\Security\Middleware\AuthGuardMiddleware` protects routes that require a logged-in user. On each request it:

1. Ensures the session is started and validates the **session fingerprint** — a mismatch logs the user out and denies the request.
2. Requires a session `auth` array with a non-empty `uid`, otherwise responds **401 Unauthorized** (plain text) — or issues a **302 redirect** if you pass a `redirect` parameter.
3. Optionally enforces roles: pass `roles` as a comma-separated list; the user needs **at least one** of them or gets **403 Forbidden**.

```php
// config/middleware.php — register aliases (none are built in for these)
return [
    'aliases' => [
        'auth'  => System\Security\Middleware\AuthGuardMiddleware::class,
        'guest' => System\Security\Middleware\GuestOnlyMiddleware::class,
    ],
];
```

```php
// Routes
$r->get('/dashboard', 'DashboardController@index')
  ->middleware('auth', ['redirect' => '/login']);

$r->group('/admin', ['middleware' => ['auth']], function ($r) {
    // admin-only routes
})->middleware('auth', ['roles' => 'admin', 'redirect' => '/login']);
```

## GuestOnlyMiddleware

`System\Security\Middleware\GuestOnlyMiddleware` is the inverse — it redirects **already-authenticated** users away from pages like login and registration. If the session has an `auth` array with a non-empty `uid`, it redirects to the configured target (default `/`, settable via the constructor or `setRedirectTo()`); guests pass through.

```php
$r->get('/login', 'AuthController@showLogin')->middleware('guest');
```

::: warning Gotchas
- `AuthGuardMiddleware` and `GuestOnlyMiddleware` ship **without built-in aliases**. Reference them by fully-qualified class name or register your own alias in `config/middleware.php` (as shown above) — `'auth'` and `'guest'` are conventions, not defaults.
- `auth_user()` returns whatever was stored at login — it is session data, not a fresh database record. Re-fetch the model when you need current data.
- `LoginThrottle::onFailure()` blocks the PHP worker for up to 1.5 s by design. Call it only on genuine failures.
- Always establish sessions through `SessionGuard::onLoginSuccess()` rather than writing `$_SESSION['auth']` directly — you get session-ID regeneration and the hijack fingerprint for free.
- The fingerprint binds sessions to the user agent and IP `/24` prefix. Users on aggressively rotating mobile IPs may occasionally be logged out.
:::

## Related

- [Security Overview](/security/overview)
- [CSRF Protection](/security/csrf)
- [JWT](/security/jwt)
- [Crypto & Passwords](/security/crypto)
- [Session](/essentials/session)
- [Middleware](/essentials/middleware)
