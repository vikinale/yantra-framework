# Query Builder

Yantra's query builder provides a fluent, injection-safe interface for constructing SQL. Every column and table identifier is validated, and all values are bound as parameters. The builder class itself (`System\Database\QueryBuilder`) is abstract — you use it through a model, `Model::query()`, or `Database::sql()`.

```php
use System\Database\Database;

$users = Database::sql()
    ->table('users')
    ->where('is_active', '=', 1)
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->get();          // array of associative arrays
```

## Getting a builder

```php
use System\Database\Database;
use System\Database\Model;
use App\Models\User;

$qb = Database::sql()->table('users');   // generic builder on any table
$qb = Model::query('users');             // same thing via the base model
$qb = User::query();                     // bound to the model's table
```

## SELECT queries

```php
// Choose columns
$users = $qb->table('users')->select('id', 'name', 'email')->get();

// Conditions, ordering, limits
$users = $qb->table('users')
    ->where('is_active', '=', 1)
    ->where('age', '>=', 18)
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->offset(20)
    ->get();

// First matching row (associative array or null)
$user = $qb->table('users')->where('email', '=', 'john@example.com')->first();

// Distinct
$roles = $qb->table('users')->distinct()->select('role')->get();

// Raw select expressions
$stats = $qb->table('orders')
    ->selectRaw('COUNT(*) as total, SUM(amount) as revenue')
    ->where('status', '=', 'completed')
    ->first();
```

## WHERE clauses

`where()` always takes **three arguments** — column, operator, value (there is no two-argument shorthand). Allowed operators: `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `IS`, `IS NOT`, `BETWEEN`, `NOT BETWEEN`.

```php
->where('status', '=', 'active')
->where('age', '>', 18)
->orWhere('role', '=', 'admin')

// Comparison shorthands
->whereEqual('status', 'active')          // where('status', '=', ...)
->whereNotEqual('role', 'guest')
->whereLessThan('age', 65)
->whereLessThanOrEqual('age', 65)
->whereGreaterThan('score', 10)
->whereGreaterThanOrEqual('score', 10)
->orWhereEqual('role', 'admin')

// IN / NOT IN
->whereIn('status', ['active', 'pending'])
->whereNotIn('role', ['banned', 'suspended'])

// NULL checks
->whereNull('deleted_at')
->whereNotNull('email_verified_at')

// BETWEEN — note: two separate bounds, not an array
->whereBetween('created_at', '2025-01-01', '2025-12-31')

// Raw fragments with bindings
->whereRaw('YEAR(created_at) = ?', [2025])
```

## JOINs

```php
$results = $qb->table('users')
    ->join('posts', 'users.id', '=', 'posts.user_id')          // INNER JOIN
    ->leftJoin('profiles', 'users.id', '=', 'profiles.user_id')
    ->select('users.name', 'posts.title', 'profiles.bio')
    ->get();
```

`join($table, $first, $operator, $second, $type = 'INNER')` accepts the join type as its fifth argument; `leftJoin()` is the built-in shorthand for `LEFT` joins.

## Aggregates

`count()` and `exists()` are available directly on the builder. Other aggregates are expressed with `selectRaw()`, or computed on fetched rows via the `Collection` class (`sum`, `avg`, `min`, `max`):

```php
$count = $qb->table('users')->where('is_active', '=', 1)->count();
$any   = $qb->table('users')->where('role', '=', 'admin')->exists();   // SELECT 1 ... LIMIT 1

// SQL-level aggregates via selectRaw
$stats = $qb->table('orders')
    ->selectRaw('SUM(amount) as total, AVG(amount) as average, MAX(amount) as top')
    ->where('status', '=', 'paid')
    ->first();

// Or aggregate in PHP on the fetched rows
$total = collect($qb->table('orders')->get())->sum('amount');
```

## GROUP BY

```php
$stats = $qb->table('orders')
    ->select('user_id')
    ->selectRaw('COUNT(*) as order_count, SUM(amount) as total_spent')
    ->groupBy('user_id')
    ->get();
```

`clearGroupBy()`, `clearOrderBy()`, and `clearLimitOffset()` reset the respective clauses on an existing builder.

## INSERT

`insert()` returns a `bool`. To get the new ID, use `lastInsertId()` on the builder (models inherit it) or `Database::getLastInsertId()`:

```php
$qb = Database::sql()->table('users');
$qb->insert(['name' => 'John', 'email' => 'john@example.com']);
$id = $qb->lastInsertId();

// Batch insert
$qb->table('users')->batchInsert([
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob',   'email' => 'bob@example.com'],
]);
```

### Insert ignore

Skip rows that would violate a unique key (MySQL/MariaDB):

```php
$qb->table('users')->ignore()->insert([
    'email' => 'john@example.com',
    'name'  => 'John',
]);
```

### Upserts (`ON DUPLICATE KEY UPDATE`)

Chain the upsert clause **before** calling `insert()`:

```php
// Update these columns to the incoming insert values on conflict
$qb->table('users')
    ->onDuplicateKeyUpdateFromInsert(['name'])
    ->insert(['email' => 'john@example.com', 'name' => 'John']);

// MySQL 8.0.20+: use the alias syntax instead of VALUES()
$qb->table('users')
    ->onDuplicateKeyUpdateFromInsert(['name'], 'new')
    ->insert(['email' => 'john@example.com', 'name' => 'John']);

// Or specify explicit update values on conflict
$qb->table('users')
    ->onDuplicateKeyUpdate(['name' => 'John (updated)', 'updated_at' => date('Y-m-d H:i:s')])
    ->insert(['email' => 'john@example.com', 'name' => 'John']);
```

## UPDATE & DELETE

```php
// Update — returns the number of affected rows
$affected = $qb->table('users')
    ->where('id', '=', 1)
    ->update(['name' => 'Jane Doe']);

// Delete — returns the number of deleted rows
$deleted = $qb->table('users')->where('is_active', '=', 0)->delete();
```

## Common Table Expressions (CTEs)

`with()` takes a CTE name and a raw SQL string (treated as trusted). `withQuery()` accepts another builder and merges its bindings safely:

```php
// Raw SQL CTE
$top = Database::sql()
    ->table('ranked_users')
    ->with('ranked_users', 'SELECT *, ROW_NUMBER() OVER (ORDER BY score DESC) AS rank FROM users')
    ->where('rank', '<=', 10)
    ->get();

// CTE built from another QueryBuilder (bindings carried over)
$recent = Database::sql()->table('orders')->where('created_at', '>=', '2025-01-01');

$stats = Database::sql()
    ->table('recent')
    ->withQuery('recent', $recent)
    ->selectRaw('COUNT(*) AS c')
    ->first();
```

## Pagination

```php
$paginator = $qb->table('users')
    ->where('is_active', '=', 1)
    ->orderBy('name')
    ->paginate(15);                 // LengthAwarePaginator (runs COUNT(*))

$paginator = $qb->table('logs')
    ->orderBy('id', 'DESC')
    ->simplePaginate(50);           // Paginator (no COUNT — fetches perPage+1)
```

Both signatures are `(int $perPage = 15, string $pageName = 'page', ?int $page = null)`; the current page is read from `$_GET[$pageName]` when `$page` is null. See [Pagination](/database/pagination) for the full paginator API.

## Transactions

```php
use System\Database\Database;

Database::beginTransaction();
try {
    Database::sql()->table('accounts')->where('id', '=', 1)->update(['balance' => 900]);
    Database::sql()->table('accounts')->where('id', '=', 2)->update(['balance' => 1100]);
    Database::commit();
} catch (\Throwable $e) {
    Database::rollBack();
    throw $e;
}
```

::: warning Gotchas
- There is **no** `sum()`, `avg()`, `max()`, `min()`, `having()`, `rightJoin()`, `increment()`, or `decrement()` on the query builder. Use `selectRaw()` for SQL-level aggregates, or `Collection::sum()/avg()/min()/max()` on fetched rows. Only `count()` and `exists()` are built in.
- `where()` requires the operator — `where('status', 'active')` is an error; write `where('status', '=', 'active')` or `whereEqual('status', 'active')`.
- `whereBetween()` takes two scalar bounds (`whereBetween('col', $from, $to)`), not an array.
- `insert()` returns `bool`, not the new ID — fetch the ID via `lastInsertId()` afterwards.
- `ignore()`, `onDuplicateKeyUpdate()`, and `onDuplicateKeyUpdateFromInsert()` are MySQL/MariaDB features and must be chained **before** `insert()`.
- CTE SQL passed to `with()` is embedded as-is — never interpolate user input into it. Prefer `withQuery()` when the CTE needs bindings.
:::

## Related

- [Database: Getting Started](/database/getting-started)
- [Models & ORM](/database/models)
- [Pagination](/database/pagination)
- [Collections](/features/collections)
- [API Reference: Query Builder](/api/query-builder)
