# Production Deployment Checklist

A short, ordered checklist for taking a Yantra app to production: flip the environment switches, cache your routes, lock down the security middleware, and point the web server at the right directory. Each item reuses verified behaviour from [Configuration](/guide/configuration), [Middleware](/essentials/middleware), and the [Security Overview](/security/overview).

```bash
# On the server, after pulling the release:
php yantra env:set production      # or set APP_ENV/APP_DEBUG in .env directly
php yantra cache:clear             # flush stale route/view caches
php yantra routes:cache            # compile route cache for speed
```

## 1. Set the environment

In your production `.env`, turn off debug and mark the environment:

```ini
APP_ENV=production
APP_DEBUG=false
```

`env()` casts the boolean words automatically, so `APP_DEBUG=false` returns a real `false`. Two things to remember from [Configuration](/guide/configuration):

- A `.env` that exists but **fails to parse throws at boot** — this is deliberate, so a broken file can't silently fall back to dev defaults (and the wrong database).
- Values containing `( ) { } | & ! ~` **must be quoted**: `APP_NAME="Acme & Co"`.

## 2. Cache routes

Compile the route table so every request skips route discovery:

```bash
php yantra routes:cache      # writes GET.php, POST.php, __index.php, __errors.php
```

Re-run this on every deploy — the cache is a snapshot of your current routes. To undo it during debugging:

```bash
php yantra routes:clear
```

## 3. Clear application caches

Flush anything left over from the previous release (routes, views, etc.):

```bash
php yantra cache:clear
```

Run `cache:clear` **before** `routes:cache` if you're rebuilding from scratch, so the fresh cache isn't immediately wiped.

## 4. Configure the global security middleware

Production should run the full security stack. Global middleware is read from `App/Config/middleware.php`, key `global` (see [Middleware](/essentials/middleware) and [Security Overview](/security/overview)):

```php
// App/Config/middleware.php
return [
    'global' => [
        'sec.normalize',   // 1. reject malformed/oversized requests first
        'sec.headers',     // 2. baseline security response headers
        'sec.cookies',     // 3. harden session ini before session_start()
        'sec.csrf',        // 4. block forged state-changing requests
        'sec.audit',       // 5. forensic audit trail
    ],
];
```

These are all built-in aliases. Order matters: `sec.cookies` must run **before** anything starts the session, since its `ini_set` calls are ineffective after `session_start()`.

Apply `auth.jwt` and `rate.limit` per route **group** rather than globally:

```php
$r->group('/api', ['middleware' => ['auth.jwt', 'rate.limit']], function ($r) {
    // ...
});
```

Add `sec.csp` once your templates render nonce-based scripts. Note that `auth`, `guest`, and `cors` are **not** built-in aliases — if you use them, register them in the project-root `config/middleware.php` under `aliases` first.

## 5. Point the web root at `public/`

The web server's document root must be the `public/` directory, never the project root — that keeps `.env`, `App/`, and `storage/` outside the served tree. A typical Nginx block:

```nginx
server {
    root /var/www/myapp/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
}
```

## 6. Make `storage/` writable

The application writes caches, session data, and logs under `storage/`, so the PHP process user (e.g. `www-data`) must own or be able to write it:

```bash
chown -R www-data:www-data storage
chmod -R u+rwX storage
```

## 7. Know where logs land

The application log is at `storage/logs/app.log`. Security audit entries (`sec.audit` and `System\Security\Audit\Audit`) are written via PHP's `error_log()`, so they land wherever your SAPI's error log points — configure PHP-FPM's `error_log` in production so those entries are captured.

## 8. Schedule cron, if you use the scheduler

If your app has scheduled tasks, add the every-minute entry now (see the [Cron Scheduling cookbook](/cookbook/cron-scheduling)):

```
* * * * * cd /var/www/myapp && php yantra schedule:run >> storage/logs/schedule.log 2>&1
```

## Quick checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false` in `.env`
- [ ] `.env` parses cleanly (it throws at boot otherwise)
- [ ] `php yantra cache:clear` then `php yantra routes:cache`
- [ ] Global `sec.*` middleware stack configured in `App/Config/middleware.php`
- [ ] `auth.jwt` / `rate.limit` applied to API route groups
- [ ] Web root points at `public/`
- [ ] `storage/` writable by the PHP process user
- [ ] PHP-FPM `error_log` configured; app log at `storage/logs/app.log`
- [ ] Cron scheduled if the app uses the scheduler

::: warning Gotchas
- **Never point the web root at the project root** — only `public/` should be served, or `.env` and source become reachable.
- **`APP_DEBUG=true` in production leaks stack traces.** Set it to `false` and verify with a deliberate 500 that no trace is shown.
- **Re-run `routes:cache` on every deploy.** A stale route cache serves the previous release's routes; clear it with `routes:clear` if routes misbehave.
- **`sec.cookies` must stay near the top of the global stack** — after `session_start()` its hardening `ini_set` calls do nothing.
- **HSTS only emits over HTTPS.** Behind a TLS-terminating proxy, make sure `X-Forwarded-Proto: https` reaches PHP or `sec.headers` won't add the header.
:::

## Related

- [Configuration](/guide/configuration) — `.env`, `env()`, and config files
- [Middleware](/essentials/middleware) — the global stack, groups, and aliases
- [Security Overview](/security/overview) — what each `sec.*` middleware protects against
- [CLI](/features/cli) — `routes:cache`, `cache:clear`, `env:set`
- [Cron Scheduling cookbook](/cookbook/cron-scheduling) — production cron setup
