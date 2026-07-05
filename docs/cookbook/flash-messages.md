# Flash Messages & the Post/Redirect/Get Pattern

This recipe implements the classic **Post/Redirect/Get** flow: a form POSTs, the controller saves, sets a one-shot flash message (and old input for repopulation), then issues a `303 See Other` redirect so a browser refresh never re-submits. On the GET that follows, the view reads the flash and any old input.

```php
use System\Session\SessionStore;

SessionStore::setFlash('success', 'Profile updated!');
return $this->redirectAfterPost('/profile');
```

## Flash data basics

Flash values live in the session until they are **read once**, then vanish — perfect for a "saved successfully" notice that must survive exactly one redirect.

```php
use System\Session\SessionStore;

SessionStore::setFlash('success', 'User saved successfully!');
SessionStore::setFlash('error', 'Something went wrong.');

$msg = SessionStore::getFlash('success');          // returns and removes it
$msg = SessionStore::getFlash('success', null);    // with a default
```

`getFlash()` pulls **and deletes** — call it once and hold the result if you need the value more than once in the same request.

## Redirecting after a POST

`redirectAfterPost(string $url)` (on `BaseController`) returns a `303 See Other` redirect. The 303 status tells the browser to follow with a GET, so a later refresh re-requests the target page instead of re-POSTing the form.

```php
public function update(): Response
{
    // ... validate and persist ...

    SessionStore::setFlash('success', 'Profile updated!');

    return $this->redirectAfterPost('/profile');   // 303 -> GET /profile
}
```

## Repopulating the form with `old()`

When validation fails you want to redirect back to the form **with the user's input preserved**. The global `old($key, $default)` helper reads a flashed value stored under `_old_input.<key>`, so before redirecting you flash each field you want to keep — skipping sensitive ones like passwords:

```php
public function store(): Response
{
    $validator = Validator::make($this->req()->all(), [
        'name'  => 'required|string|max:100',
        'email' => 'required|email',
    ]);

    if ($validator->fails()) {
        // Preserve input for the next request (never flash passwords/tokens)
        foreach ($this->req()->only(['name', 'email']) as $key => $value) {
            SessionStore::setFlash('_old_input.' . $key, $value);
        }

        SessionStore::setFlash('error', 'Please fix the errors below.');

        return $this->redirectAfterPost('/register');
    }

    // ... persist ...

    SessionStore::setFlash('success', 'Welcome aboard!');
    return $this->redirectAfterPost('/dashboard');
}
```

In the view, read the flash message and pre-fill inputs with `old()`. Because `old()` reads a flash value, each key is consumed on first read:

```php
<?php if ($msg = \System\Session\SessionStore::getFlash('error')): ?>
    <div class="alert error"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if ($msg = \System\Session\SessionStore::getFlash('success')): ?>
    <div class="alert success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<form method="post" action="/register">
    <input name="name"  value="<?= htmlspecialchars(old('name')) ?>">
    <input name="email" value="<?= htmlspecialchars(old('email')) ?>">
    <button type="submit">Register</button>
</form>
```

`old(string $key, mixed $default = ''): mixed` returns the flashed value for that field, or the default (empty string) when there is none — so on a fresh GET with nothing flashed, the inputs render empty.

## The full flow

1. `GET /register` renders the form. `old()` returns `''` for every field — empty inputs.
2. `POST /register` fails validation → flash `_old_input.*` for each kept field, flash an `error` message, `redirectAfterPost('/register')` (303).
3. The browser follows with `GET /register`. The view reads the `error` flash (consumed) and repopulates inputs via `old()` (each consumed). A refresh now shows a clean form.
4. On a successful POST, flash a `success` message and redirect to the destination page, where it is shown once.

::: warning Gotchas
- **`getFlash()` and `old()` consume the value on first read.** Reading the same flash key twice returns the default the second time — capture it in a variable if you need it more than once.
- **`old()` only sees keys you flashed under `_old_input.`** There is no automatic old-input capture tied to the `old()` helper — flash each field yourself with `SessionStore::setFlash('_old_input.' . $key, $value)` before redirecting.
- **Never flash passwords or tokens** into old input — exclude them from the fields you loop over.
- **Always escape old input in views** with `htmlspecialchars()`; it is raw user input being echoed back into HTML.
- **Use the 303 redirect (`redirectAfterPost()`), not a plain 302 or re-render**, so a post-submit refresh does not re-POST the form.
- **Flash keys are stored literally** — `setFlash('_old_input.email', …)` uses the exact string key; it is not dot-expanded into a nested array, which is why the `old()` helper can pull it back by the same literal key.
:::

## Related

- [Session & Cookies](/essentials/session) — the full `SessionStore` flash API
- [Validation](/essentials/validation) — producing the errors you flash
- [Responses](/essentials/responses) — redirects and status codes
- [Requests](/essentials/requests) — `only()` for whitelisting fields to preserve
