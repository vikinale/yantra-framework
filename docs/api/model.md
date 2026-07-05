# API Reference: Model

`System\Database\Model`

The Active Record base class. `Model` extends `MasterModel` (which extends the [`QueryBuilder`](/api/query-builder)), so a model instance **is** a query builder with mass assignment, casting, timestamps, relationships, scopes, accessors/mutators, lifecycle events, and serialization layered on top. It implements `ArrayAccess` and `JsonSerializable`. For a narrative guide with worked examples, see [Models & ORM](/database/models).

::: warning Arrays vs. instances — read this first
The method names encode their return type, and this is the most common source of confusion:

- `find()`, `first()`, `get()`, `all()` return **raw associative arrays**.
- `findModel()`, `firstModel()`, `getModels()` return **hydrated `Model` instances**.
- `create()` returns the **new record's ID** (`int|string`), not a model.
- There is **no `findOrFail()`** and no `updateOrCreate()`/`firstOrCreate()` — check for `null` yourself.

Only the `*Model`/`getModels` variants give you accessors, casts, relations, and `save()`.
:::

## Method Table

### Query entry points (static)

Static calls are routed through `__callStatic()`, which starts a fresh query (`static::query()`) and either resolves a `scope{Name}` method or forwards to a builder method. So `User::where(...)`, `User::whereIn(...)`, `User::query()`, and any custom scope all work statically.

| Signature | Returns | Description |
| --- | --- | --- |
| `static query(string $table = ''): static` | `static` | Start a fresh, reset query chain. Uses `$tableName` if `$table` is omitted. |
| `static all(): array` | `array` | All rows as **raw arrays** (`(new static)->get()`). |
| `static withRelations(string ...$relations): static` | `static` | Begin a query that will eager-load the named relations (defined in `relations()`). |
| `static hydrate(array $attributes): static` | `static` | Wrap a raw row array in a model instance (no DB hit). |
| `static hydrateAndLoad(array $rows, array $eagerLoadRelations = []): array` | `static[]` | Hydrate many rows and optionally eager-load relations. |
| `static __callStatic(string $method, array $args)` | mixed | Magic: resolves `scope{Method}` or forwards to a query builder method. |

### Reading (instance)

| Signature | Returns | Description |
| --- | --- | --- |
| `find(mixed $id): ?array` | `?array` | Row matching the primary key, as a **raw array**, or `null`. |
| `findModel(mixed $id): ?static` | `?static` | Row matching the primary key as a **hydrated model**, or `null`. |
| `first(): ?array` | `?array` | First row of the current query as a **raw array**. |
| `firstModel(): ?static` | `?static` | First row as a **hydrated model** (applies pending eager loads). |
| `get(): array` | `array` | All rows of the current query as **raw arrays**. |
| `getModels(): array` | `static[]` | All rows as **hydrated models** (applies pending eager loads). |

### Writing (instance)

| Signature | Returns | Description |
| --- | --- | --- |
| `save(): bool` | `bool` | Insert (no PK set) or update (PK set). Fires lifecycle events. |
| `create(array $data): int\|string` | `int\|string` | Insert filtered by `$fillable`, maintain timestamps; returns the **new ID** (`0` on failure). Bypasses mutators/events. |
| `fill(array $attributes): static` | `static` | Mass-assign (filtered by `$fillable`), running each value through its mutator. |
| `updateById(int\|string $id, array $data): bool` | `bool` | Update one row by PK (fillable-filtered, timestamps bumped). No events. |
| `delete(): int` | `int` | Delete this instance by PK; fires `deleting`/`deleted`. Falls back to the query builder `delete()` when no PK is present. |
| `deleteById(int\|string $id): int` | `int` | Delete one row by PK. No events. |

### Relationships (instance)

| Signature | Returns | Description |
| --- | --- | --- |
| `hasOne(string $related, string $foreignKey, string $localKey = 'id')` | `MasterModel` | One-to-one; returns a query scoped to the child. |
| `hasMany(string $related, string $foreignKey, string $localKey = 'id')` | `MasterModel` | One-to-many; returns a query scoped to the children. |
| `belongsTo(string $related, string $foreignKey, string $ownerKey = 'id')` | `MasterModel` | Inverse relation; returns a query scoped to the owner. |
| `belongsToMany(string $related, string $pivotTable, string $foreignPivotKey, string $relatedPivotKey, string $parentKey = 'id', string $relatedKey = 'id')` | `BelongsToMany` | Many-to-many via a pivot table. |
| `setRelation(string $name, mixed $value): void` | `void` | Manually attach a loaded relation value. |

::: warning Relationship keys are explicit
Unlike some ORMs, Yantra relationship methods **do not guess** foreign keys from class names. `$foreignKey` (and the pivot keys for `belongsToMany`) are **required** positional arguments. `hasOne`/`hasMany`/`belongsTo` return a query you finish with `->firstModel()`, `->getModels()`, etc. Eager loading via `withRelations()` reads the `relations()` array (see below), not these methods.
:::

### Serialization & ArrayAccess

| Signature | Returns | Description |
| --- | --- | --- |
| `toArray(): array` | `array` | Attributes with casts applied, plus loaded relations (recursively). |
| `jsonSerialize(): mixed` | `array` | Delegates to `toArray()` (used by `json_encode()`). |
| `offsetExists/offsetGet/offsetSet/offsetUnset` | — | `ArrayAccess` over attributes + loaded relations. |
| `__get(string $key)` / `__set(string $key, $value)` | mixed / void | Relation → accessor → cast on read; mutator on write. |

### Inherited metadata (from `MasterModel`)

| Signature | Returns | Description |
| --- | --- | --- |
| `getPrimaryKey(): string` | `string` | Primary key column (default `id`). |
| `setPrimaryKey(string $primaryKey): void` | `void` | Override the primary key column. |
| `lastInsertId(): string\|false` | `string\|false` | ID of the last insert on this connection. |

Every public [`QueryBuilder`](/api/query-builder) method (`where`, `whereIn`, `orderBy`, `limit`, `join`, `count`, `paginate`, `insert`, `update`, `delete`, …) is also available on a model instance, since `Model` extends it.

## Configuration properties

Set these on your subclass:

```php
class User extends Model
{
    protected ?string $tableName = 'users';
    protected array $fillable = ['name', 'email', 'password'];
    protected array $casts = ['is_active' => 'bool', 'settings' => 'json'];
    protected bool $timestamps = true;
    protected string $createdAt = 'created_at';
    protected string $updatedAt = 'updated_at';
    protected string $connection = 'branch';   // named connection (multi-DB)
}
```

Supported cast types: `int`/`integer`, `float`/`double`/`real`, `string`, `bool`/`boolean`, `array`/`json` (JSON strings decode to arrays). Casts apply on **read** only.

## Selected examples

### `save()` — insert vs. update

`save()` decides based on whether the primary-key attribute is present:

```php
$user = new User();
$user->name = 'Jane';
$user->save();          // INSERT — $user->id is now populated

$user->name = 'Janet';
$user->save();          // UPDATE — id is present
```

It fires events in this order: `saving` → `creating`/`updating` → `created`/`updated` → `saved`. Returning `false` from a *before* event (`saving`, `creating`, `updating`) cancels the operation, and `save()` returns `false`. See [Model events](/database/models#model-events).

### `create()` returns an ID, not a model

```php
$id = User::create([
    'name'  => 'Jane',
    'email' => 'jane@example.com',
]);                     // int|string — the new row's ID

$user = User::findModel($id);   // load it if you need the instance
```

`create()` applies the `$fillable` filter and timestamps, then inserts the array **directly** — it does not run mutators or fire events. Use `fill()` + `save()` when mutators must run.

### Eager loading with `relations()` + `withRelations()`

Declare relations in a static `relations()` map, then request them:

```php
class User extends Model
{
    protected static function relations(): array
    {
        return [
            'posts' => ['hasMany', Post::class, 'user_id', 'id'],
            'roles' => ['belongsToMany', Role::class, 'user_roles', 'user_id', 'role_id'],
        ];
    }
}

$users = User::withRelations('posts', 'roles')->getModels();
$users[0]->posts;   // Collection of Post models, loaded in one batch
```

Eager loading only runs through `getModels()` / `firstModel()` — the raw-array variants ignore it.

### Scopes chain statically and fluently

```php
public function scopeActive($query): void { $query->where('is_active', '=', 1); }
public function scopeRole($query, string $role): void { $query->where('role', '=', $role); }

$admins = User::active()->role('admin')->getModels();
```

`__callStatic()` starts the chain; `__call()` continues it. Calling an undefined method that is not a scope throws `BadMethodCallException`.

::: warning Gotchas
- Bulk query operations (`User::where(...)->update()` / `->delete()`, `updateById()`, `deleteById()`) do **not** fire model events. Only instance `save()`/`delete()` do.
- `create()` bypasses accessors/mutators and events.
- `all()`, `find()`, `first()`, `get()` return raw arrays — no casts-on-nested, no relations, no `save()`.
- The `phone` example patterns you may see elsewhere for validation are unrelated; for model attribute transformation use accessors/mutators.
- Relationship foreign keys are required — there is no naming-convention fallback.
:::

## Related

- [Models & ORM guide](/database/models)
- [Relationships](/database/relationships)
- [Pagination](/database/pagination)
- [API Reference: QueryBuilder](/api/query-builder)
- [API Reference: Collection](/api/collection)
