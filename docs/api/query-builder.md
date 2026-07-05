# API Reference: QueryBuilder

`System\Database\QueryBuilder` (abstract)

The fluent, PDO-backed SQL builder underneath every model. It is `abstract` — you never instantiate it directly; it is reached through `MasterModel` → [`Model`](/api/model), so a model instance exposes all of these methods. Identifiers are validated through `System\Database\Support\Identifier`, values are always **bound** as named placeholders (`:pN`), and every `WHERE` operator is checked against an allow-list, so the builder is safe against SQL injection by construction. For a task-oriented guide, see [Query Builder](/database/query-builder).

::: warning What this builder does NOT have
Verify against the source before reaching for a familiar method — several common ones are absent:

- **No aggregate helpers** other than `count()`: there is no `sum()`, `avg()`, `min()`, or `max()`. (Those exist on [`Collection`](/api/collection), not here.)
- **No `having()`**, no `rightJoin()` helper (though `RIGHT` is a valid `join()` type), no `increment()`/`decrement()`.
- **No `orderByDesc()`** — pass `'DESC'` to `orderBy()`.
- Upserts are expressed through `ignore()` + `insert()`, `onDuplicateKeyUpdate()`, and `onDuplicateKeyUpdateFromInsert()` — there is no single `upsert()` method.
:::

## Method Table

### Table / source

| Signature | Returns | Description |
| --- | --- | --- |
| `table(string $table): static` | `static` | Set the table for `INSERT`/`UPDATE`/`DELETE` (and legacy `SELECT` fallback). |
| `from(string $table): static` | `static` | Set the `FROM` table for `SELECT`. |
| `fromAs(string $table, string $alias): static` | `static` | `FROM table AS alias`. |

### Select

| Signature | Returns | Description |
| --- | --- | --- |
| `select(array\|string ...$columns): static` | `static` | Choose columns (`select('id','name')` or `select(['id','name'])`); no args → `*`. |
| `selectAs(string $column, string $alias): static` | `static` | Append `column AS alias`. |
| `selectExprAs(string $expr, string $alias, array $bindings = []): static` | `static` | Replace select list with a bound raw expression aliased. |
| `selectRaw(string $raw): static` | `static` | Append trusted raw SQL to the select list. |
| `clearSelect(): static` | `static` | Empty the select list. |
| `distinct(bool $on = true): static` | `static` | Toggle `SELECT DISTINCT`. |

### Joins

| Signature | Returns | Description |
| --- | --- | --- |
| `join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): static` | `static` | Add a join (`type` ∈ `INNER`/`LEFT`/`RIGHT`). |
| `leftJoin(string $table, string $first, string $operator, string $second): static` | `static` | Shorthand for a `LEFT` join. |
| `joinAs(string $table, string $alias, string $first, string $operator, string $second, string $type = 'INNER'): static` | `static` | Join an aliased table. |
| `leftJoinAs(string $table, string $alias, string $first, string $operator, string $second): static` | `static` | Aliased `LEFT` join. |

### Where clauses

| Signature | Returns | Description |
| --- | --- | --- |
| `where(string $column, string $operator, mixed $value, string $boolean = 'AND'): static` | `static` | Core where; operator checked against an allow-list. |
| `orWhere(string $column, string $operator, mixed $value): static` | `static` | `OR` variant of `where()`. |
| `whereEqual` / `orWhereEqual` / `whereNotEqual` | `static` | `=` / `OR =` / `!=` shortcuts. |
| `whereLessThan` / `whereLessThanOrEqual` / `whereGreaterThan` / `whereGreaterThanOrEqual` | `static` | `<`, `<=`, `>`, `>=` shortcuts. |
| `whereIn(string $column, array $values, string $boolean = 'AND'): static` | `static` | `IN (...)`. Empty set → `1=0`. |
| `whereNotIn(string $column, array $values, string $boolean = 'AND'): static` | `static` | `NOT IN (...)`. Empty set → `1=1`. |
| `whereLike` / `whereNotLike` | `static` | `LIKE` / `NOT LIKE`. |
| `whereNull` / `whereNotNull` | `static` | `IS NULL` / `IS NOT NULL`. |
| `whereBetween(string $column, mixed $from, mixed $to, string $boolean = 'AND'): static` | `static` | `BETWEEN ? AND ?`. |
| `whereNotBetween(string $column, mixed $from, mixed $to, string $boolean = 'AND'): static` | `static` | `NOT BETWEEN`. |
| `whereRaw(string $raw, array $bindings = [], string $boolean = 'AND'): static` | `static` | Trusted raw predicate; `?` placeholders must match binding count. |
| `orWhereRaw(string $raw, array $bindings = []): static` | `static` | `OR` variant of `whereRaw()`. |
| `whereGroup(callable $cb, string $boolean = 'AND'): static` | `static` | Parenthesized group; the callback receives a nested where-proxy. |
| `orWhereGroup(callable $cb): static` | `static` | `OR` variant of `whereGroup()`. |

### Grouping, ordering, limits

| Signature | Returns | Description |
| --- | --- | --- |
| `groupBy(string ...$columns): static` | `static` | Append `GROUP BY` columns. |
| `orderBy(string $column, string $direction = 'ASC'): static` | `static` | Append an `ORDER BY` term. |
| `limit(int $limit): static` | `static` | `LIMIT` (clamped to ≥ 0). |
| `offset(int $offset): static` | `static` | `OFFSET` (clamped to ≥ 0). |
| `clearOrderBy` / `clearGroupBy` / `clearLimitOffset` | `static` | Reset the respective clause. |

### CTEs, upsert & soft-delete config

| Signature | Returns | Description |
| --- | --- | --- |
| `with(string $cteName, string $cteSql): static` | `static` | Add a `WITH` CTE from trusted raw SQL. |
| `withQuery(string $cteName, QueryBuilder $cteQuery): static` | `static` | Add a CTE from another builder, rebasing its placeholders. |
| `ignore(bool $on = true): static` | `static` | `INSERT IGNORE`. |
| `onDuplicateKeyUpdate(array $data): static` | `static` | `ON DUPLICATE KEY UPDATE col = ?` with bound values. |
| `onDuplicateKeyUpdateFromInsert(array $columns, ?string $alias = null): static` | `static` | Update from the incoming row (`VALUES(col)` or `alias.col`). |
| `softDeleteColumn(string $column, mixed $value = 1): static` | `static` | Configure `softDelete()` to update this column. |

### Terminals (run SQL)

| Signature | Returns | Description |
| --- | --- | --- |
| `get(): array` | `array` | Fetch all rows as **raw arrays**. |
| `first()` | `?array` | Fetch the first row (auto `LIMIT 1`) as a **raw array**, or `null`. |
| `count(string $column = '*'): int` | `int` | `SELECT COUNT(col)`. Respects joins + where. |
| `exists(): bool` | `bool` | `SELECT 1 … LIMIT 1` existence check. |
| `insert(array $data): bool` | `bool` | Single-row insert (honors `ignore()`/on-duplicate). |
| `batchInsert(array $rows): bool` | `bool` | Multi-row insert; all rows must share identical column keys. |
| `update(array $data): int` | `int` | Update; **refuses without a `WHERE`**. Returns affected rows. |
| `delete(): int` | `int` | Physical delete; **refuses without a `WHERE`**. Returns affected rows. |
| `softDelete(): int` | `int` | Update the configured soft-delete column; refuses without a `WHERE`. |
| `lastInsertId(): string\|false` | `string\|false` | Last insert ID on this connection. |
| `copyUpsertChunked(QueryBuilder $source, ?array $insertColumns = null, string $cursorColumn = 'id', int $chunk = 5000, ?int $maxBatches = null, bool $requireWhereOnSource = false): int` | `int` | Cursor-paged `INSERT … SELECT` for large-table copy/upsert. |

### Pagination

| Signature | Returns | Description |
| --- | --- | --- |
| `paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): LengthAwarePaginator` | paginator | Runs a `COUNT` + page slice. |
| `simplePaginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): Paginator` | paginator | Fetches `perPage + 1` to detect a next page (no `COUNT`). |

### Introspection & reset

| Signature | Returns | Description |
| --- | --- | --- |
| `getSql(): string` | `string` | Compile the current `SELECT` to SQL (idempotent; rebuilds bindings). |
| `getBindings(): array` | `array` | Current named bindings. |
| `reset(): void` | `void` | Clear all builder state (called at model construction and `query()`). |

On a [`Model`](/api/model) subclass you additionally get `firstModel()`, `getModels()` (hydrated instances), `find()`/`findModel()`, `create()`, `save()`, etc.

## Selected examples

### Allowed operators

`where()` accepts only: `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `IS`, `IS NOT`, `BETWEEN`, `NOT BETWEEN`. Anything else throws `QueryException`.

```php
User::query()
    ->where('is_active', '=', 1)
    ->whereIn('role', ['admin', 'editor'])
    ->orderBy('created_at', 'DESC')
    ->limit(20)
    ->get();          // raw arrays  (use ->getModels() for instances)
```

### Grouped / parenthesized conditions

```php
User::query()->whereGroup(function ($w) {
    $w->where('company_id', '=', 1)
      ->orWhereNull('company_id');
})->where('is_active', '=', 1)->get();
// WHERE (company_id = ? OR company_id IS NULL) AND is_active = ?
```

### Upsert via on-duplicate

There is no `upsert()`; compose it:

```php
User::query()->table('users')
    ->onDuplicateKeyUpdate(['name' => 'Jane', 'updated_at' => date('Y-m-d H:i:s')])
    ->insert(['id' => 1, 'name' => 'Jane', 'email' => 'jane@example.com']);

// or update straight from the inserted row:
User::query()->table('users')
    ->onDuplicateKeyUpdateFromInsert(['name', 'email'])
    ->insert(['id' => 1, 'name' => 'Jane', 'email' => 'jane@example.com']);
```

### Counting (the only aggregate)

```php
$active = User::query()->where('is_active', '=', 1)->count();
```

For `sum`/`avg`/`min`/`max`, fetch rows and use a [`Collection`](/api/collection), or write a `selectRaw()` expression.

::: warning Gotchas
- **`update()` and `delete()` throw `QueryException` if no `WHERE` clause is set** — this is a deliberate guard against mass mutation. `softDelete()` behaves the same.
- **`get()`/`first()` return raw arrays.** On a model, use `getModels()`/`firstModel()` for hydrated instances.
- **`whereRaw()`/`selectRaw()`/`with()` are trusted** — they are not escaped. Only pass constant SQL; put user input in the `$bindings` array (validated to match `?` count).
- **Empty `whereIn([])` compiles to `1=0`** (and `whereNotIn([])` to `1=1`) rather than erroring — so an empty set returns no rows, which is usually what you want.
- **`count()` ignores `select`, `groupBy`, `orderBy`, `limit`** — it wraps its own `COUNT(...)`; for a grouped count, count the resulting rows.
:::

## Related

- [Query Builder guide](/database/query-builder)
- [API Reference: Model](/api/model)
- [Pagination](/database/pagination)
- [API Reference: Collection](/api/collection)
