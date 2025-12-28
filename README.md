# Yantra Framework (Core)

This package contains the **core** Yantra framework only:

- HTTP request/response (PSR-7 기반)
- Routing (Router, RouteCollector, RouteCompiler)
- Hooks (actions/filters)
- Database utilities (Database, QueryBuilder, Model)
- Helpers + Exceptions

## What was removed

To keep the framework core clean, the following were removed from this tree:

- `App/` (application layer)
- `themes/`, `resource/`, `storage/` (UI + runtime assets)
- Theme/Page rendering (`System\Theme`, `System\Core\Page*`, `System\Yantra`)

## Bootstrap helpers (optional)

If you want the procedural helper functions (`add_action`, `apply_filter`, `site_url`, ...), load:

```php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yantra/framework/bootstrap/functions.php';
```

## Configuration

By default, config files are loaded from:

- `YANTRA_BASEPATH/config/*.php`

You can override in your app bootstrap:

```php
\System\Config::setAppPath(APPPATH);
\System\Config::setConfigDir('Config');
``` 