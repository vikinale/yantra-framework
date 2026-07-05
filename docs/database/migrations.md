# Database Migrations

Migrations are version control for your schema. Each migration is a PHP file in `database/migrations/` that returns an object implementing `System\Contracts\MigrationInterface`, with `up()` to apply the change and `down()` to reverse it. The runner executes each migration inside a transaction and records it in the `yt_migrations` table.

```bash
php yantra make:migration create_users_table
php yantra migrate
```

## Creating migrations

```bash
php yantra make:migration create_users_table
php yantra make:migration add_role_to_users
```

This creates a timestamped file such as `database/migrations/2026_07_05_120000_create_users_table.php`. Files run in filename order.

## Writing migrations

A migration file must **return** an instance of `MigrationInterface`. Both methods receive the `System\Database\Database` connection — **not** a raw PDO object:

```php
<?php
declare(strict_types=1);

use System\Contracts\MigrationInterface;
use System\Database\Database;
use System\Database\Schema\Schema;
use System\Database\Schema\Blueprint;

return new class implements MigrationInterface {
    public function up(Database $db): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 100);
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->string('role', 20)->default('user');
            $table->boolean('is_active')->default(true);
            $table->text('bio')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(Database $db): void
    {
        Schema::dropIfExists('users');
    }
};
```

For raw SQL, use the `$db` handle directly (`$db->execute($sql, $params)` or `$db->exec($ddl)`).

## The Schema facade

```php
Schema::create('users', function (Blueprint $table) { ... });   // CREATE TABLE
Schema::drop('users');                                          // DROP TABLE IF EXISTS
Schema::dropIfExists('users');                                  // alias of drop()
```

The generated DDL adapts to the connection driver (MySQL/MariaDB or SQLite).

## Blueprint column types

The complete set of column methods on `System\Database\Schema\Blueprint`:

| Method | Produces (MySQL) | Notes |
| --- | --- | --- |
| `increments('id')` | `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` | SQLite: `INTEGER PRIMARY KEY AUTOINCREMENT` |
| `bigIncrements('id')` | `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` | SQLite: same as `increments` |
| `integer('qty')` | `INTEGER` | |
| `bigInteger('views')` | `BIGINT` | SQLite: `INTEGER` |
| `tinyInteger('priority')` | `TINYINT` | SQLite: `INTEGER` |
| `decimal('price', 10, 2)` | `DECIMAL(10,2)` | defaults: precision 8, scale 2 |
| `float('rating')` | `FLOAT` | SQLite: `REAL` |
| `double('amount')` | `DOUBLE` | SQLite: `REAL` |
| `string('name', 255)` | `VARCHAR(255)` | default length 255 |
| `text('description')` | `TEXT` | |
| `longText('content')` | `LONGTEXT` | SQLite: `TEXT` |
| `boolean('is_active')` | `TINYINT(1)` | SQLite: `INTEGER` |
| `json('metadata')` | `JSON` | SQLite: `TEXT` |
| `enum('status', ['a', 'b'])` | `ENUM('a','b')` | SQLite: `TEXT` |
| `date('publish_date')` | `DATE` | |
| `datetime('event_at')` | `DATETIME` | |
| `timestamp('verified_at')` | `TIMESTAMP` | |
| `timestamps()` | two `DATETIME` columns | nullable `created_at` + `updated_at` |
| `binary('payload')` | `BLOB` | |

## Column modifiers

Modifiers apply to the most recently added column:

```php
$table->string('nickname')->nullable();
$table->string('role')->default('user');       // strings/bools/null are quoted safely
$table->string('email')->unique();
$table->integer('votes')->unsigned();
```

## Indexes & foreign keys

```php
// Composite or single-column index (named idx_{table}_{cols} automatically)
$table->index('status');
$table->index('user_id', 'created_at');

// Foreign key — attaches to the most recently added column
// foreign(column, referencesTable, referencesColumn = 'id',
//         onDelete = 'CASCADE', onUpdate = 'CASCADE')
$table->integer('user_id')->unsigned()
      ->foreign('user_id', 'users');

$table->integer('team_id')->unsigned()
      ->foreign('team_id', 'teams', 'id', 'SET NULL', 'CASCADE');
```

Note the signature: `foreign()` takes the referenced table and column as plain arguments — there is no `references()->on()` chain, and no standalone `primary()` method (primary keys come from `increments()`/`bigIncrements()`; unique constraints from the `unique()` modifier).

## Running migrations

```bash
# Run all pending migrations
php yantra migrate

# Rollback the last batch (or a specific batch)
php yantra migrate:rollback
php yantra migrate:rollback --batch=2

# DEV ONLY: rollback everything, re-run all migrations, then seed
php yantra migrate:refresh --force
php yantra migrate:refresh --force --no-seed

# Show ran/pending status
php yantra migrate:status
```

All commands accept `--path=...` to override the migrations directory (default `database/migrations`, or the `migrations_path` key in `App/Config/db.php`).

`migrate:refresh` is guarded: it only runs in the `development`, `local`, or `testing` environment and always requires `--force`. After migrating it runs the default seeder unless `--no-seed` is passed.

Each migration runs inside its own transaction — if `up()` throws, that migration is rolled back and the runner stops. A migration lock prevents concurrent runs.

::: warning Gotchas
- The migration file must `return` the migration instance. A class definition alone (without `return new ...`) will fail with "Invalid migration file (must return MigrationInterface)".
- `up()`/`down()` are type-hinted against `System\Database\Database`, **not** `PDO`. The stub generated by `make:migration` currently type-hints `PDO` — change those hints to `Database` (as in the example above) or the class will not satisfy the interface.
- Blueprint modifiers (`nullable()`, `default()`, `unique()`, `unsigned()`, `foreign()`) always target the **last column added** — order your chains accordingly.
- `Schema::drop()` already uses `DROP TABLE IF EXISTS`, so `drop()` and `dropIfExists()` behave identically.
- DDL on MySQL commits implicitly, so a failed migration containing multiple DDL statements may be partially applied despite the transaction wrapper.
:::

## Related

- [Database: Getting Started](/database/getting-started)
- [Seeders](/database/seeders)
- [CLI Commands](/features/cli)
- [Testing: Getting Started](/testing/getting-started)
