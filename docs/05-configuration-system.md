# 5. Configuration System

## 5.1 Overview
Yantra configuration is loaded through `System\Config` and stored as PHP arrays.
Configuration should be treated as read-only during a request.

## 5.2 Recommended config files
- config/app.php
- config/database.php
- config/cache.php
- config/session.php

## 5.3 Example: app config
```php
<?php
declare(strict_types=1);

return [
    'name' => 'Yantra App',
    'environment' => getenv('APP_ENV') ?: 'production',
    'debug' => getenv('APP_DEBUG') === 'true',
];
```

## 5.4 Access patterns
```php
$env = Config::get('app.environment') ?? 'production';
```

## 5.5 Security
- Store secrets in environment variables.
- Do not commit credentials.
- Keep config outside web root.

> **Warning:** Never expose `config/` or `storage/` directories via the web server.
