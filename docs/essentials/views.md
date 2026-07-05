# Views

Yantra uses native PHP templates — no custom template language to learn, no compilation step, full IDE support. On top of plain PHP it layers a layout/section system, partials, and view namespaces, with the `e()` helper for HTML-safe output.

```php
// In a controller
return $this->render('users.index', ['users' => $users]);
```

```php
<!-- views/users/index.php -->
<h1>Users</h1>
<?php foreach ($users as $user): ?>
    <p><?= e($user['name']) ?></p>
<?php endforeach; ?>
```

## Rendering Views

Views are rendered from controllers with `render()`. Dot notation maps to directories (`users.index` → `views/users/index.php`), and an optional third argument wraps the view in a layout.

```php
// In a controller
return $this->render('users.index', ['users' => $users]);

// With layout
return $this->render('users.show', ['user' => $user], 'layouts.main');
```

## PHP Templates

Data passed to `render()` is extracted into template variables. Always escape output with `e()`:

```php
<!-- views/users/index.php -->
<?php /** @var array $users */ ?>

<h1>Users</h1>
<?php foreach ($users as $user): ?>
    <div>
        <h2><?= e($user['name']) ?></h2>
        <p><?= e($user['email']) ?></p>
    </div>
<?php endforeach; ?>
```

## Sections & Layouts

Views can define named sections that layouts pull in with `View::yield()`:

```php
// In a view
<?php View::section('sidebar'); ?>
    <ul>
        <li><a href="/dashboard">Dashboard</a></li>
        <li><a href="/settings">Settings</a></li>
    </ul>
<?php View::endSection(); ?>
```

```php
// In layout
<aside><?= View::yield('sidebar') ?></aside>
```

## Partials

Render a reusable fragment with its own data using `View::partial()`:

```php
// Render a partial view
<?= View::partial('components.alert', ['type' => 'success', 'message' => 'Saved!']) ?>
```

## View Namespaces

Namespaces let modules or packages register their own view directories. Reference namespaced views with the `namespace::view` syntax.

```php
// Register a namespace
$viewRenderer->addNamespace('admin', '/path/to/admin/views');

// Use namespaced view
return $this->render('admin::dashboard', $data);
```

## Static View Facade

Render a view to a string anywhere — handy for emails or any HTML generated outside the request/response cycle:

```php
use System\View\View;

$html = View::render('emails.welcome', ['name' => 'John']);
```

::: warning Gotchas
- Native PHP templates do **not** auto-escape. Every piece of user-controlled data must go through `e()` — a raw `<?= $user['name'] ?>` is an XSS hole.
- Every `View::section()` needs a matching `View::endSection()`; an unclosed section swallows the rest of the template's output.
:::

## Related

- [Controllers](/essentials/controllers)
- [Responses](/essentials/responses)
- [Themes](/features/themes)
- [Helpers](/features/helpers)
