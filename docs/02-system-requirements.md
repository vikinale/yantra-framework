# 2. System Requirements & Environment

## 2.1 PHP version
Yantra targets modern PHP.
- Minimum: PHP 8.1+
- Recommended: latest stable PHP 8.x for production

## 2.2 Required extensions
Required:
- json, mbstring, pdo, openssl, session

Recommended:
- opcache (production)
- redis (if using Redis cache)
- pdo_mysql / pdo_pgsql (depending on DB)

## 2.3 Web server
Yantra is web-server agnostic:
- Apache (with rewrite)
- Nginx + PHP-FPM

### Apache rewrite example
```apacheconf
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

## 2.4 Environments
Common values: development, staging, production

Development:
- display errors, verbose logs

Production:
- hide errors from clients, log internally

> **Warning:** Never enable `display_errors` in production.


## 2.5 Filesystem permissions
Only `storage/` should be writable:
- storage/cache, storage/logs, storage/sessions (if used)

## 2.6 Timezone
Set timezone early in bootstrap:
```php
date_default_timezone_set('UTC');
```
