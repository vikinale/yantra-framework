# 1. Setup & First Route

Welcome to the **Build a Blog** tutorial. Over seven steps you'll build a small but complete blog — posts stored in a database, full CRUD with server-rendered views, form validation, session login, a JSON API secured with a JWT, and a test suite. Every code sample is copy-paste runnable and the pieces carry forward from step to step, so keep the same table, model, and route names throughout.

In this first step you'll scaffold the application, configure `.env`, wire up `public/index.php`, register your first route, and return a view from a controller.

::: tip Prerequisites
This is the starting step — you only need PHP 8, Composer, and a database (MySQL/MariaDB or SQLite) reachable from your machine. The [Installation guide](/guide/installation) covers the underlying requirements in more detail.
:::

## Install & scaffold

Create the project, pull in the framework, and generate the application skeleton:

```bash
composer require yantra/framework
php vendor/bin/yantra app:scaffold
```

`app:scaffold` creates the standard `App/` layout — `App/Controllers`, `App/Models`, `App/Routes`, `App/Config`, `views/`, `database/`, `storage/`, and a `public/` entry point. See [Directory Structure](/guide/directory-structure) for the full tree.

::: tip
Throughout the tutorial the CLI is invoked as `php vendor/bin/yantra`. If your scaffold placed a `yantra` shim in the project root, `php yantra <command>` is equivalent — the docs use both forms interchangeably.
:::

## Configure `.env`

Create a `.env` file in the project root. For the blog we'll use MySQL, but SQLite works just as well (see the note below):

```ini
APP_NAME="Yantra Blog"
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=yantra_blog
DB_USERNAME=root
DB_PASSWORD=secret
DB_CHARSET=utf8mb4

SESSION_DRIVER=native

# Used in step 6 for the JSON API
JWT_SECRET=change-me-to-a-long-random-string
```

Create the `yantra_blog` database in MySQL before continuing. Prefer SQLite? Set just these two keys instead of the `DB_*` block above:

```ini
DB_DRIVER=sqlite
DB_DATABASE=storage/database.sqlite
```

::: warning Gotchas
- The `.env` file is parsed with PHP's INI parser. Quote any value containing `( ) { } | & ! ~` — an unparseable `.env` stops the app from booting on purpose, so it never runs against default config.
- Keep `JWT_SECRET` long and random. We use it in step 6; a weak secret undermines the whole token scheme.
:::

## The entry point — `public/index.php`

`app:scaffold` generates this for you, but here's the full file so you know what every line does. Your web server's document root must point at `public/`.

```php
<?php
declare(strict_types=1);

define('BASEPATH', dirname(__DIR__));
define('APPPATH', BASEPATH . '/App');

require BASEPATH . '/vendor/autoload.php';

use System\Core\Application;

$app = Application::create(env('APP_ENV', 'production'));

$app->initRoutes([
    'web' => APPPATH . '/Routes/web.php',
    'api' => APPPATH . '/Routes/api.php',
]);

$app->boot()->run();
```

`BASEPATH` and `APPPATH` must be defined **before** `Application::create()` — the framework uses them to locate `.env`, config, and storage.

## Define your first route

Routes live in `App/Routes/web.php`. The file returns a closure that receives a `RouteCollector`. Register a home route pointing at a controller action:

```php
<?php
// App/Routes/web.php
declare(strict_types=1);

use System\Core\Routing\RouteCollector;

return function (RouteCollector $r) {
    $r->get('/', 'HomeController@index')->name('home');
};
```

The string `'HomeController@index'` means "the `index` method on `App\Controllers\HomeController`". See [Routing](/essentials/routing) for parameters, groups, and named routes.

## Return a view from a controller

Create `App/Controllers/HomeController.php`. Application controllers extend `System\Core\BaseController`, which gives you `render()` and the other response helpers:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use System\Core\BaseController;
use System\Http\Response;

class HomeController extends BaseController
{
    public function index(): Response
    {
        return $this->render('home', [
            'title'   => 'Yantra Blog',
            'tagline' => 'A blog built step by step.',
        ]);
    }
}
```

Now the view. Yantra uses plain PHP templates — no template language, no compile step. Create `views/home.php`:

```php
<!-- views/home.php -->
<?php /** @var string $title */ ?>
<?php /** @var string $tagline */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($title) ?></title>
</head>
<body>
    <h1><?= e($title) ?></h1>
    <p><?= e($tagline) ?></p>
    <p><a href="/posts">View posts &rarr;</a></p>
</body>
</html>
```

The data array passed to `render()` is extracted into template variables. Always run user-facing values through `e()` — native PHP templates do **not** auto-escape, so a raw `<?= $title ?>` would be an XSS hole. (The `/posts` link 404s for now; you'll build it in step 3.)

## Run it

Use PHP's built-in server pointed at `public/`:

```bash
php -S localhost:8000 -t public
```

Open `http://localhost:8000` and you should see your title and tagline.

::: warning Gotchas
- If you define a constructor on a controller, you **must** call `parent::__construct($request, $response)` — every `BaseController` helper depends on the request/response pair it stores.
- Dot notation in view names maps to directories: `render('posts.index')` resolves to `views/posts/index.php`. `render('home')` resolves to `views/home.php`.
:::

## Next

You've got a booting app that serves a route and a view. Next, give the blog somewhere to store posts.

→ **[2. Database & Models](/tutorial/02-database)**

## Related

- [Routing](/essentials/routing)
- [Controllers](/essentials/controllers)
- [Views](/essentials/views)
- [Configuration](/guide/configuration)
