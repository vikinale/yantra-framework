# Session & Cookies

Yantra's `SessionStore` is a static facade over pluggable session adapters, with dot-notation keys for nested data and one-shot flash messages. The `session()` helper offers a shorter syntax, and the `Cookie` class handles hardened HTTP cookies with sensible defaults (HttpOnly, SameSite=Lax).

```php
use System\Session\SessionStore;

SessionStore::set('user.name', 'John');
$name = SessionStore::get('user.name');
```

## Basic Operations

```php
use System\Session\SessionStore;

// Set values
SessionStore::set('user.name', 'John');
SessionStore::set('cart', ['item1', 'item2']);

// Get values (with optional defaults)
$name = SessionStore::get('user.name');
$cart = SessionStore::get('cart', []);

// Check existence
if (SessionStore::has('user.name')) {
    // ...
}

// Remove values
SessionStore::remove('user.name');

// Get all session data
$all = SessionStore::all();

// Clear entire session
SessionStore::flush();
```

Keys support dot notation: `user.name` reads and writes the `name` key nested inside `user`.

## Flash Messages

Flash data survives exactly until it is read — ideal for "saved successfully" notices across a redirect.

```php
// Set flash data (auto-removed after read)
SessionStore::setFlash('success', 'User saved successfully!');
SessionStore::setFlash('error', 'Something went wrong.');

// Read flash data (returns and removes)
$success = SessionStore::getFlash('success');
```

See the [Flash Messages cookbook](/cookbook/flash-messages) for a full pattern with redirects and views.

## The session() Helper

```php
// Get value
$name = session('user.name');

// Set value
session('user.name', 'John');

// Get SessionStore instance
$store = session();
```

## Session Adapters

- **NativeSessionAdapter** — Uses PHP's built-in session handling (default)
- **RedisSessionAdapter** — Redis-backed sessions for distributed apps

## Cookies

The `System\Http\Cookie` class wraps PHP cookie handling with per-instance defaults for path, domain, and security attributes.

### Creating a Cookie Instance

The constructor sets the attributes applied to every cookie the instance writes:

```php
use System\Http\Cookie;

// Defaults: path '/', empty domain, secure=false, httpOnly=true, sameSite='Lax'
$cookie = new Cookie();

// Custom attributes
$cookie = new Cookie(
    path: '/',
    domain: 'example.com',
    secure: true,
    httpOnly: true,
    sameSite: 'Strict'
);
```

### Reading and Writing

```php
// Set a cookie with a TTL in seconds from now (default 3600)
$cookie->set('theme', 'dark', 86400);         // returns bool

// Read (with optional default)
$theme = $cookie->get('theme', 'light');

// Check existence
if ($cookie->has('theme')) {
    // ...
}

// Delete (expires the cookie; returns false if it does not exist)
$cookie->delete('theme');
```

### Changing Attributes Later

`setParams()` replaces all attributes on an existing instance:

```php
$cookie->setParams('/', 'example.com', true, true, 'Strict');
```

::: warning Gotchas
- `Cookie::set()` and `Cookie::delete()` return `false` if HTTP headers have already been sent — write cookies before any output.
- Flash values are removed on first read; call `getFlash()` once and store the result if you need the value more than once in the same request.
- `Cookie::delete()` must be called with the same path/domain attributes the cookie was set with, or the browser will not match and clear it.
:::

## Related

- [Requests](/essentials/requests)
- [Responses](/essentials/responses)
- [Flash Messages cookbook](/cookbook/flash-messages)
- [SessionStore API](/api/session-store)
- [Security Overview](/security/overview)
