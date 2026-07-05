# Configuration

Yantra's configuration has two layers: environment variables loaded from a `.env` file (machine-specific secrets and switches), and PHP config files in `App/Config/` that return arrays (application structure and defaults). You read the first with `env()` and the second with `Config::get()` or the `config()` helper.

```php
// App/Config/app.php
return [
    'name'  => env('APP_NAME', 'Yantra'),
    'debug' => env('APP_DEBUG', false),
];

// Anywhere in your application
$name = config('app.name');
```

## Environment Variables

Yantra loads the `.env` file from your project root at boot. Access values with the `env()` helper:

```php
$debug  = env('APP_DEBUG', false);
$dbHost = env('DB_HOST', 'localhost');
```

### Type Casting

Boolean-like strings are automatically cast to real PHP types:

| `.env` value | `env()` returns |
|--------------|-----------------|
| `true` or `(true)` | `true` (bool) |
| `false` or `(false)` | `false` (bool) |
| `null` or `(null)` | `null` |
| `empty` or `(empty)` | `''` (empty string) |
| anything else | the raw string |

Note that `"0"` and `"1"` are **not** cast to booleans — they remain valid numeric strings. Only the explicit boolean words are converted. If the variable is not set at all, `env()` returns the default you passed.

### .env Format

The `.env` file is parsed with PHP's INI parser (whole-line `#` and `;` comments are stripped first):

```ini
APP_NAME="My Yantra App"
APP_ENV=development
APP_DEBUG=true

; This is a comment
# So is this — but only on its own line
```

::: warning Gotchas
- Because parsing goes through `parse_ini_string()`, values containing special characters — `( ) { } | & ! ~` — **must be quoted**: `APP_NAME="Notes & Things"`.
- A `.env` that exists but fails to parse causes the application to **throw at boot** rather than silently falling back to defaults. This is deliberate: a silently ignored `.env` once meant migrations ran against the wrong database.
- Use `;` or whole-line `#` for comments; inline `#` comments are not supported by the INI parser.
:::

## Config Files

Configuration files live in `App/Config/` and each returns a PHP array. Files are loaded lazily: the first segment of a dot-notation key names the file, so `config('app.name')` loads `App/Config/app.php` on first access and caches it.

```php
// App/Config/app.php
return [
    'name'        => env('APP_NAME', 'Yantra'),
    'environment' => env('APP_ENV', 'production'),
    'debug'       => env('APP_DEBUG', false),
    'url'         => env('APP_URL', 'http://localhost'),
    'timezone'    => 'UTC',
    'locale'      => 'en',
];
```

A config file **must** return an array — anything else throws a `RuntimeException`. A missing file is not an error; lookups against it simply return your default.

## Reading and Writing Config

Access values by dot-notation via the static `Config` facade or the `config()` helper:

```php
use System\Config;

// Read (with optional default)
$appName = Config::get('app.name');
$debug   = Config::get('app.debug', false);

// Helper equivalent
$appName = config('app.name');
$debug   = config('app.debug', false);

// Set values at runtime (in-memory only, not persisted)
Config::set('app.timezone', 'Asia/Kolkata');
```

Dot-notation traverses nested arrays to any depth: `config('mail.smtp.port')` reads `['smtp' => ['port' => ...]]` inside `App/Config/mail.php`.

### Other Facade Methods

```php
// Force-load a config file and get its full array
$all = Config::read('app');

// Deep-merge defaults under a key — existing values win over defaults
Config::merge('cache', ['driver' => 'file', 'ttl' => 3600]);
```

The `Config` facade delegates to a shared `ConfigRepository` instance, which is also bound to `ConfigInterface` in the DI container — new code can inject the interface instead of using the facade.

## Database Configuration

```php
// App/Config/db.php
return [
    'driver'   => env('DB_DRIVER', 'mysql'),
    'host'     => env('DB_HOST', 'localhost'),
    'port'     => env('DB_PORT', 3306),
    'database' => env('DB_DATABASE', 'yantra'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset'  => env('DB_CHARSET', 'utf8mb4'),
];
```

If `App/Config/db.php` is missing or returns an empty array, the application boots without a database connection — Yantra never forces one. See [Database: Getting Started](/database/getting-started).

::: tip
The `app.timezone` value is applied via `date_default_timezone_set()` during boot, so every `date()`/`time()` call — in web requests and the CLI scheduler alike — agrees with your configured zone. It falls back to UTC.
:::

## Related

- [Installation](/guide/installation)
- [Directory Structure](/guide/directory-structure)
- [Request Lifecycle](/guide/lifecycle)
- [Helpers](/features/helpers)
- [Database: Getting Started](/database/getting-started)
