# 3. CRUD & Views

In this step you'll build the full set of blog screens — list, show, create, edit, delete — with a shared layout and PHP views. Forms carry a CSRF token via `csrf_field()`, and writes redirect using the Post-Redirect-Get pattern.

::: tip You'll need step 2 first
This step builds on [2. Database & Models](/tutorial/02-database) — the `posts` table, the `Post` model, and seed data. Make sure `php vendor/bin/yantra migrate` and `db:seed` have run.
:::

## Routes

Add the seven CRUD routes to `App/Routes/web.php`. Order matters: put the static `/posts/create` route **before** `/posts/{id}` so `create` isn't captured as an id.

```php
<?php
// App/Routes/web.php
declare(strict_types=1);

use System\Core\Routing\RouteCollector;

return function (RouteCollector $r) {
    $r->get('/', 'HomeController@index')->name('home');

    $r->get('/posts', 'PostController@index')->name('posts.index');
    $r->get('/posts/create', 'PostController@create')->name('posts.create');
    $r->post('/posts', 'PostController@store')->name('posts.store');
    $r->get('/posts/{id}', 'PostController@show')->name('posts.show');
    $r->get('/posts/{id}/edit', 'PostController@edit')->name('posts.edit');
    $r->post('/posts/{id}', 'PostController@update')->name('posts.update');
    $r->post('/posts/{id}/delete', 'PostController@destroy')->name('posts.destroy');
};
```

We use `POST` for update and delete (rather than `PUT`/`DELETE`) because plain HTML forms can only send `GET` and `POST`. The controller reads the route id from its method parameter.

## The controller

Create `App/Controllers/PostController.php`. Each action returns a `Response`. Reads use `getModels()`/`findModel()` so views get objects; writes use `create()`/`updateById()`/`deleteById()` and then **redirect after POST** (HTTP 303) so a browser refresh doesn't resubmit the form.

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use System\Core\BaseController;
use System\Http\Response;
use App\Models\Post;

class PostController extends BaseController
{
    // GET /posts
    public function index(): Response
    {
        $posts = Post::query()
            ->orderBy('created_at', 'DESC')
            ->getModels();

        return $this->render('posts.index', ['posts' => $posts]);
    }

    // GET /posts/{id}
    public function show(int $id): Response
    {
        $post = (new Post)->findModel($id) ?? abort(404);

        return $this->render('posts.show', ['post' => $post]);
    }

    // GET /posts/create
    public function create(): Response
    {
        return $this->render('posts.create');
    }

    // POST /posts
    public function store(): Response
    {
        if (!$this->validateCsrf('posts')) {
            return $this->error('Invalid CSRF token', 419);
        }

        $data = $this->req()->only(['title', 'slug', 'body']);

        (new Post)->create([
            'title'     => $data['title'],
            'slug'      => $data['slug'],
            'body'      => $data['body'],
            'published' => true,
        ]);

        return $this->redirectAfterPost('/posts');
    }

    // GET /posts/{id}/edit
    public function edit(int $id): Response
    {
        $post = (new Post)->findModel($id) ?? abort(404);

        return $this->render('posts.edit', ['post' => $post]);
    }

    // POST /posts/{id}
    public function update(int $id): Response
    {
        if (!$this->validateCsrf('posts')) {
            return $this->error('Invalid CSRF token', 419);
        }

        $post = (new Post)->findModel($id) ?? abort(404);
        $data = $this->req()->only(['title', 'slug', 'body']);

        (new Post)->updateById($id, [
            'title' => $data['title'],
            'slug'  => $data['slug'],
            'body'  => $data['body'],
        ]);

        return $this->redirectAfterPost('/posts/' . $id);
    }

    // POST /posts/{id}/delete
    public function destroy(int $id): Response
    {
        if (!$this->validateCsrf('posts')) {
            return $this->error('Invalid CSRF token', 419);
        }

        (new Post)->deleteById($id);

        return $this->redirectAfterPost('/posts');
    }
}
```

Notes on the APIs used:

- `abort(404)` throws an HTTP exception — handy since there's **no `findOrFail()`**.
- `$this->req()->only([...])` pulls just the fields you name from the request.
- `create()` returns the new id; `updateById()` returns a bool; `deleteById()` returns the affected-row count.
- `validateCsrf('posts')` reads the `_csrf` field and validates it against the `posts` scope, rotating the token on success. We emit that token with `csrf_field()` below — but note the default `csrf_field()` uses the `default` scope, so we'll pass the scope explicitly (see the CSRF note at the end).

## A shared layout

Create `views/layouts/app.php`. The layout renders the child view's `content` section and any others it declares:

```php
<!-- views/layouts/app.php -->
<?php /** @var System\View\ViewRenderer $view */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($view->yield('title', 'Yantra Blog')) ?></title>
</head>
<body>
    <header>
        <a href="/">Home</a> ·
        <a href="/posts">Posts</a> ·
        <a href="/posts/create">New post</a>
    </header>

    <main>
        <?= $view->yield('content') ?>
    </main>
</body>
</html>
```

Inside a template, `$view` is the renderer instance, so `$view->yield('name')` outputs a named section (falling back to the default). The global `View` facade works too (`View::yield('content')`).

Each child view declares its layout with `View::layout(...)` and wraps its markup in a `content` section.

## The views

**`views/posts/index.php`** — list all posts:

```php
<?php use System\View\View; ?>
<?php View::layout('layouts.app'); ?>

<?php View::section('title'); ?>All posts<?php View::endSection(); ?>

<?php View::section('content'); ?>
    <h1>Posts</h1>
    <?php /** @var App\Models\Post[] $posts */ ?>
    <?php if (empty($posts)): ?>
        <p>No posts yet. <a href="/posts/create">Write the first one</a>.</p>
    <?php else: ?>
        <ul>
        <?php foreach ($posts as $post): ?>
            <li>
                <a href="/posts/<?= e((string) $post->id) ?>"><?= e($post->title) ?></a>
                — <a href="/posts/<?= e((string) $post->id) ?>/edit">edit</a>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php View::endSection(); ?>
```

**`views/posts/show.php`** — a single post plus a delete form:

```php
<?php use System\View\View; ?>
<?php View::layout('layouts.app'); ?>

<?php View::section('title'); ?><?= e($post->title) ?><?php View::endSection(); ?>

<?php View::section('content'); ?>
    <?php /** @var App\Models\Post $post */ ?>
    <article>
        <h1><?= e($post->title) ?></h1>
        <p><?= nl2br(e($post->body)) ?></p>
    </article>

    <p>
        <a href="/posts/<?= e((string) $post->id) ?>/edit">Edit</a>
    </p>

    <form method="POST" action="/posts/<?= e((string) $post->id) ?>/delete"
          onsubmit="return confirm('Delete this post?')">
        <?= csrf_field() ?>
        <button type="submit">Delete</button>
    </form>
<?php View::endSection(); ?>
```

**`views/posts/create.php`** — the new-post form:

```php
<?php use System\View\View; ?>
<?php View::layout('layouts.app'); ?>

<?php View::section('title'); ?>New post<?php View::endSection(); ?>

<?php View::section('content'); ?>
    <h1>New post</h1>
    <form method="POST" action="/posts">
        <?= csrf_field() ?>

        <p>
            <label>Title<br>
                <input type="text" name="title" value="<?= e(old('title')) ?>">
            </label>
        </p>
        <p>
            <label>Slug<br>
                <input type="text" name="slug" value="<?= e(old('slug')) ?>">
            </label>
        </p>
        <p>
            <label>Body<br>
                <textarea name="body" rows="8"><?= e(old('body')) ?></textarea>
            </label>
        </p>

        <button type="submit">Publish</button>
    </form>
<?php View::endSection(); ?>
```

**`views/posts/edit.php`** — the same form, pre-filled and pointed at the update route:

```php
<?php use System\View\View; ?>
<?php View::layout('layouts.app'); ?>

<?php View::section('title'); ?>Edit post<?php View::endSection(); ?>

<?php View::section('content'); ?>
    <?php /** @var App\Models\Post $post */ ?>
    <h1>Edit post</h1>
    <form method="POST" action="/posts/<?= e((string) $post->id) ?>">
        <?= csrf_field() ?>

        <p>
            <label>Title<br>
                <input type="text" name="title" value="<?= e(old('title', $post->title)) ?>">
            </label>
        </p>
        <p>
            <label>Slug<br>
                <input type="text" name="slug" value="<?= e(old('slug', $post->slug)) ?>">
            </label>
        </p>
        <p>
            <label>Body<br>
                <textarea name="body" rows="8"><?= e(old('body', $post->body)) ?></textarea>
            </label>
        </p>

        <button type="submit">Save</button>
    </form>
<?php View::endSection(); ?>
```

`old('title', $post->title)` shows the resubmitted value when it exists (after a failed validation redirect in step 4) and otherwise falls back to the stored post value.

## Try it

```bash
php -S localhost:8000 -t public
```

Visit `http://localhost:8000/posts` — you should see your seeded posts, be able to open one, create a new one, edit it, and delete it.

::: warning Gotchas
- `csrf_field()` emits a token for the **`default`** scope, but our controller validates the `posts` scope. To keep them in sync, either validate the default scope (`$this->validateCsrf('default')`) or render the scoped token yourself: `<input type="hidden" name="_csrf" value="<?= e(\System\Security\Csrf::token('posts')) ?>">`. For this tutorial, switch the controller checks to `$this->validateCsrf('default')` so `csrf_field()` works as-is. See [CSRF Protection](/security/csrf).
- Use `redirectAfterPost()` (HTTP 303), not `redirect()`, after form submissions so a browser refresh re-requests with `GET` and never resubmits the form.
- Native PHP templates don't auto-escape — every dynamic value goes through `e()`. `$post->id` is an int, so cast it to string before escaping.
:::

## Next

The blog has full CRUD, but it accepts anything the form sends. Let's validate it.

→ **[4. Validation](/tutorial/04-validation)**
← [2. Database & Models](/tutorial/02-database)

## Related

- [Controllers](/essentials/controllers)
- [Views](/essentials/views)
- [Routing](/essentials/routing)
- [CSRF Protection](/security/csrf)
