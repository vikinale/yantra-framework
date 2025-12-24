# 3. Installation & Setup

## 3.1 Install
Install dependencies via Composer:
```bash
composer install
```

## 3.2 Suggested structure
```text
project-root/
|-- app/
|   |-- Controllers/
|   |-- Middleware/
|   `-- Routes/
|-- config/
|-- public/
|   `-- index.php
|-- storage/
|   |-- cache/
|   |-- logs/
|   `-- sessions/
`-- vendor/
```

## 3.3 Entry point
A typical `public/index.php` bootstraps config, error settings, request/response, and dispatches routing.
```php
<?php
declare(strict_types=1);

define('BASEPATH', dirname(__DIR__));

require BASEPATH . '/vendor/autoload.php';

use System\Config;
use System\Http\Request;
use System\Http\Response;
use System\Core\Routing\Router;

// 1) Load config
$app = Config::get('app');
$env = $app['environment'] ?? 'production';

// 2) Development error settings
if ($env === 'development') {
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
    ini_set('error_log', BASEPATH . '/storage/logs/error.log');
    error_reporting(E_ALL & ~E_NOTICE);
}

// 3) Create HTTP objects
$request  = Request::fromGlobals();
$response = new Response();

// 4) Dispatch via router (route cache dir)
$router = new Router(BASEPATH . '/storage/cache/routes');
$router->dispatch($request, $response);
```

## 3.4 First run checklist
- PHP 8.1+ installed
- Web root points to `public/`
- `storage/` writable for cache/logs
- Environment set (`config/app.php`)
- Routes compiled (production)

> **Tip:** In production, compile routes during deployment and make route cache read-only.
