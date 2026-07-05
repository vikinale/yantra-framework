# SessionStore

`System\Session\SessionStore`

`SessionStore` is a static facade over a shared `System\Session\SessionManager` instance. Every method delegates to that manager, so existing call sites such as `SessionStore::get()` / `SessionStore::set()` keep working while new code can inject a `SessionInterface` via the container. Keys support **dot notation** (`user.profile.name`) for reading and writing into nested arrays, and the store carries two dedicated bags — `auth` and `flash` — whose reads are *destructive pulls* (get-and-remove). Most application code touches the store indirectly through the [`session()`, `old()`, `auth_user()`, and `auth_check()` helpers](/api/helpers).

```php
use System\Session\SessionStore;

SessionStore::set('cart.items', $items);
$items = SessionStore::get('cart.items', []);

SessionStore::setFlash('status', 'Saved!');
$status = SessionStore::getFlash('status');   // returns then removes it
```

## Methods

### Instance management

| Method | Returns | Description |
| --- | --- | --- |
| `getInstance()` | `SessionManager` | Get (lazily creating) the shared backing manager. |
| `setInstance(SessionInterface $mgr)` | `void` | Wire a specific `SessionManager` (used by bootstrap / tests). Throws `InvalidArgumentException` if `$mgr` is not a `SessionManager`. |
| `reset()` | `void` | Destroy and drop the shared instance. Call `init()` again afterwards. Intended for test isolation. |

### Lifecycle

| Method | Returns | Description |
| --- | --- | --- |
| `init(?SessionAdapterInterface $adapter = null)` | `void` | Initialize with an adapter and start the session. Call early in bootstrap, before output. Defaults to `NativeSessionAdapter`. |
| `is_init()` | `bool` | Whether an adapter has been set. |
| `ensureStarted()` | `void` | Start the session if it hasn't been started yet. |
| `setAdapter(SessionAdapterInterface $adapter)` | `void` | Replace the adapter. Throws if the session has already started. |
| `isStarted()` | `bool` | Whether the underlying session is active. |

### Core key–value API

| Method | Returns | Description |
| --- | --- | --- |
| `set(string $key, mixed $value)` | `void` | Store a value. Dot keys write into nested arrays. |
| `get(string $key, mixed $default = null)` | `mixed` | Read a value, or `$default` if absent. |
| `has(string $key)` | `bool` | Whether the (possibly nested) key exists. |
| `remove(string $key)` | `void` | Delete a key (dot notation supported). |
| `all()` | `array` | Every value in the session. |
| `clear()` | `void` | Remove all session data (keeps the session alive). |
| `regenerate(bool $deleteOldSession = true)` | `void` | Regenerate the session ID (call after privilege changes). |
| `destroy()` | `void` | Destroy the session entirely. |

### Auth & login helpers

| Method | Returns | Description |
| --- | --- | --- |
| `loginSuccess(int\|string $user_id, string $email, array $roles, string $name)` | `void` | Establish an authenticated session via `SessionGuard::onLoginSuccess()` (regenerates the ID and stores identity). |
| `auth(string $key, mixed $value = null)` | `mixed` | The `auth` bag. Two args = **set**; one arg = **pull** (get and remove). |

### Flash bag

| Method | Returns | Description |
| --- | --- | --- |
| `setFlash(string $key, mixed $value)` | `void` | Write a value to the `flash` bag. |
| `getFlash(string $key, mixed $default = null)` | `mixed` | **Pull** a flash value: returns it and removes it. |
| `removeFlash(string $key)` | `void` | Delete a flash key without reading it. |

## Examples

### Dot-notation reads and writes

```php
SessionStore::set('user.prefs.theme', 'dark');
SessionStore::has('user.prefs.theme');   // true
SessionStore::get('user.prefs.theme');   // 'dark'
SessionStore::remove('user.prefs.theme');
```

`set()` builds intermediate arrays as needed; `get()`/`has()` return the default / `false` if any segment along the path is missing or not an array.

### The auth bag pull semantics

```php
SessionStore::auth('intended_url', '/dashboard');   // set (2 args)
$url = SessionStore::auth('intended_url');          // pull — reads AND removes
$url = SessionStore::auth('intended_url');          // null the second time
```

Do not use `auth()` to store the persistent logged-in identity — reads consume it. Use `loginSuccess()` (which writes under the `auth` **key**, read back via [`auth_user()`](/api/helpers)) for durable login state.

### Flash messages across a redirect

```php
// Controller before redirect
SessionStore::setFlash('status', 'Profile updated.');
return redirect('/profile');

// View after redirect
<?php if ($msg = SessionStore::getFlash('status')): ?>
    <div class="alert"><?= e($msg) ?></div>
<?php endif; ?>
```

Because `getFlash()` pulls, the message survives exactly one subsequent request and then disappears.

### Logging a user in

```php
SessionStore::loginSuccess($user->id, $user->email, $user->roles, $user->name);
// later, anywhere:
$user = auth_user();     // ['email' => ..., 'name' => ..., ...] or null
$ok   = auth_check();    // bool
```

::: warning Gotchas
- **`getFlash()` and `auth()` (one-arg) are destructive reads.** Each call removes the value. Capture it in a variable if you need it more than once in a request.
- **`loginSuccess()` takes `email` before `roles`** — `loginSuccess($id, $email, $roles, $name)`. Note this differs from `TestClient::actingAs($userId, $roles, $name, $email)`, whose argument order is not the same.
- **`clear()` vs `destroy()`:** `clear()` empties data but keeps the session; `destroy()` tears the session down. Use `regenerate()` (not `destroy()`) right after login to prevent session fixation — `loginSuccess()` already does this for you.
- **Dot keys with an empty root or tail** (e.g. `.foo` or `foo.`) are treated as a single literal key, not a nested path.
- After `reset()` you must call `init()` again before the store is usable.
:::

## Related

- [Session guide](/essentials/session)
- [Helpers API Reference](/api/helpers)
- [Authentication](/security/authentication)
- [Security API Reference](/api/security)
