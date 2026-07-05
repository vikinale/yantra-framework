# Installation

Yantra installs as a single Composer package with no transitive production dependencies. Getting a new application running takes two commands: install the framework, then scaffold the application skeleton.

```bash
composer require yantra/framework
php vendor/bin/yantra app:scaffold
```

## Requirements

- PHP >= 8.0
- PDO extension (`ext-pdo`)
- Fileinfo extension (`ext-fileinfo`)
- Iconv extension (`ext-iconv`)
- A supported database: MySQL, MariaDB, PostgreSQL, or SQLite

::: tip
The database is optional at boot time — Yantra does not force a connection. If no `db` config is present, the application boots without one.
:::

## Install the Framework

```bash
composer require yantra/framework
```

## Application Scaffolding

After installing the framework, scaffold a new application:

```bash
php vendor/bin/yantra app:scaffold
```

This creates the standard application directory structure under `App/` — controllers, models, views, routes, config, and more. See [Directory Structure](/guide/directory-structure) for the full layout.

## Manual Setup

If you prefer to wire things up yourself, two files are all you need.

### 1. Entry point — `public/index.php`

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

Point your web server's document root at `public/` so `index.php` handles every request.

### 2. Environment file — `.env`

Create a `.env` file in your project root:

```ini
APP_NAME="My Yantra App"
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost

DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=my_database
DB_USERNAME=root
DB_PASSWORD=secret
DB_CHARSET=utf8mb4

CACHE_DRIVER=file
SESSION_DRIVER=native

MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
```

::: warning Gotchas
- The `.env` file is parsed with PHP's INI parser. Quote any value containing special characters such as `( ) { } | & ! ~` — an unparseable `.env` makes the application refuse to boot (by design, so it never silently runs against default config).
- `BASEPATH` and `APPPATH` must be defined **before** creating the `Application` — the framework uses them to locate `.env`, config files, and storage paths.
:::

## Verify the Install

```bash
php vendor/bin/yantra list       # List all available CLI commands
php vendor/bin/yantra db:check   # Test the database connection
```

## Related

- [Introduction](/guide/introduction)
- [Directory Structure](/guide/directory-structure)
- [Configuration](/guide/configuration)
- [Tutorial: Setup](/tutorial/01-setup)
