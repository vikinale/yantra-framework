# Database: Getting Started

Yantra ships with a lightweight PDO wrapper (`System\Database\Database`) that provides lazy connections, a shared singleton, static transaction helpers, and raw query execution. Everything else in the database layer — the [query builder](/database/query-builder), [models](/database/models), [migrations](/database/migrations), and [seeders](/database/seeders) — builds on top of it.

```php
use System\Database\Database;

$row = Database::query('SELECT * FROM users WHERE id = ?', [1])->fetch();
```

## Configuration

Database settings live in `App/Config/db.php` and return a plain array. Values are typically pulled from the environment via `env()`:

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

The config loader tries `Config::get('db')` first, then `Config::get('database')`, and finally falls back to the `DB_*` environment variables directly, so the framework can connect even without a config file.

Two optional keys are also recognised:

| Key | Purpose |
| --- | --- |
| `options` | Array of PDO options merged over the defaults (`ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES => false`). |
| `DSN` | A full PDO DSN string. When present it is used verbatim, bypassing the driver-specific DSN building. |

### Supported drivers

- **`mysql` / `mariadb`** — the default. A `mysql:` DSN is built from `host`, `port`, `database`, and `charset`. MariaDB uses the same PDO driver.
- **`sqlite`** — set `'driver' => 'sqlite'` and point `database` at a file path, or use `':memory:'` for an in-memory database (handy for tests).
- **Other PDO drivers (e.g. PostgreSQL)** — supply an explicit `DSN` key (for example `'DSN' => 'pgsql:host=localhost;dbname=app'`) together with `username`/`password`. Since the DSN is passed straight to PDO, any driver your PHP build supports will work.

```php
// SQLite example
return [
    'driver'   => 'sqlite',
    'database' => BASEPATH . '/storage/app.sqlite',   // or ':memory:'
];
```

## The Database singleton

`Database::getInstance()` returns a shared, lazily-connected instance. Nothing touches the network until the first query runs.

```php
use System\Database\Database;

$db = Database::getInstance();        // shared singleton
$db = Database::getInstance(false);   // fresh, independent instance

// Instance API
$rows = $db->fetchAll('SELECT * FROM users WHERE role = ?', ['admin']);
$row  = $db->fetch('SELECT * FROM users WHERE id = ?', [1]);   // null when not found
$stmt = $db->execute('UPDATE users SET active = 1 WHERE id = ?', [1]);
$stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
$db->exec('SET FOREIGN_KEY_CHECKS = 0');   // raw, unprepared (DDL etc.)
$id   = $db->lastInsertId();               // string|false, cast as needed
```

### Static helpers

For quick access without holding an instance:

```php
// Run SQL and get the PDOStatement back (positional or named bindings)
$stmt  = Database::query('SELECT * FROM posts WHERE status = :s', ['s' => 'published']);
$posts = $stmt->fetchAll();

// Last insert id (string|false — cast to int if you need one)
$id = Database::getLastInsertId();

// Start a query-builder chain (returns a Model instance)
$users = Database::sql()->table('users')->whereEqual('is_active', 1)->get();
```

## Transactions

The static transaction helpers operate on the singleton connection:

```php
use System\Database\Database;

Database::beginTransaction();
try {
    Database::query('UPDATE accounts SET balance = balance - 100 WHERE id = ?', [1]);
    Database::query('UPDATE accounts SET balance = balance + 100 WHERE id = ?', [2]);
    Database::commit();
} catch (\Throwable $e) {
    Database::rollBack();
    throw $e;
}

Database::inTransaction();   // true while a transaction is open, false otherwise
```

Instance-level equivalents exist as `_beginTransaction()`, `_commit()`, `_rollBack()`, and `_inTransaction()` when you manage a non-singleton connection yourself.

## Checking connectivity: `db:check`

The CLI ships a safe, read-only health check:

```bash
php yantra db:check
```

It verifies the connection, then prints the PDO driver name, server version, current database, charset/collation (MySQL/MariaDB), and whether the `yt_migrations` table exists.

## Multiple connections

Yantra supports named connections through `System\Database\ConnectionManager`, a name-keyed registry designed for topologies such as one organization-wide database plus one database per branch/tenant.

```php
use System\Database\ConnectionManager;
use System\Database\Database;

// Register a lazy factory at boot (resolved once, then cached)
ConnectionManager::register('org', fn() => new Database([
    'driver' => 'mysql', 'host' => 'db.internal', 'database' => 'org_main',
    'username' => 'app', 'password' => env('ORG_DB_PASSWORD'),
]));

// Bind an already-built connection for the current request (e.g. per tenant)
ConnectionManager::set('branch', new Database($branchConfig));

ConnectionManager::has('branch');    // true if resolvable (instance or factory)
$db = ConnectionManager::get('org'); // resolve by name (throws if unknown)

// Drop the request-scoped binding at teardown (factories are kept)
ConnectionManager::forget('branch');
```

Models pick their connection through a `protected string $connection` property (default `'branch'`). When the named connection is not registered, the model falls back to `System\Database\ConnectionResolver::get()`, which in turn falls back to the `Database` singleton — so single-database apps work with zero setup.

```php
class Organization extends Model
{
    protected ?string $tableName = 'organizations';
    protected string $connection = 'org';   // resolve via ConnectionManager::get('org')
}
```

Two supporting pieces round out the system:

- **`ConnectionResolver`** — a single-slot global registry (`set()` / `get()` / `clear()`) used by the schema builder and as the legacy fallback. `get()` returns the `Database` singleton when nothing was set.
- **`ConnectionProxy`** — a `Database` subclass that owns no PDO connection and delegates every call, at call time, to whatever is currently bound under its name (`new ConnectionProxy('branch')`). Inject it into container-cached services so they always talk to the request's current branch database instead of a frozen snapshot.

::: warning Gotchas
- `Database::commit()` and `Database::rollBack()` throw if no instance exists yet — always pair them with `Database::beginTransaction()` as in the example above.
- `lastInsertId()` / `getLastInsertId()` return a **string** (or `false`), mirroring PDO. Cast to `int` when needed.
- Connections are lazy: constructing `Database` never connects. Invalid credentials only surface on the first query.
:::

## Related

- [Query Builder](/database/query-builder)
- [Models & ORM](/database/models)
- [Migrations](/database/migrations)
- [Seeders](/database/seeders)
- [Configuration](/guide/configuration)
- [Testing: Getting Started](/testing/getting-started)
