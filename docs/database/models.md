# Models & ORM

Yantra models (`System\Database\Model`) are a thin Active Record layer on top of the [query builder](/database/query-builder). They add mass assignment, attribute casting, timestamps, [relationships](/database/relationships), query scopes, accessors/mutators, lifecycle events, and serialization — while staying close to the raw SQL underneath.

```php
use App\Models\User;

$user = User::findModel(1);          // hydrated User instance (or null)
$user->name = 'Jane Doe';
$user->save();
```

::: warning Raw arrays vs. model instances
This is the single most important thing to know about Yantra models:

- `find()`, `first()`, `get()`, and `all()` return **raw associative arrays** (or arrays of arrays).
- `findModel()`, `firstModel()`, and `getModels()` return **hydrated model instances**.
- `create()` returns the **new record's ID** (`int|string`) — not a model.
- There is **no `findOrFail()`** — check for `null` yourself.

If you need accessors, casts, relations, or `save()`, use the `*Model`/`getModels` variants.
:::

## Creating models

```bash
php yantra make:model User
php yantra db:make-model users   # generate a model from an existing table
```

## Model definition

```php
<?php
namespace App\Models;

use System\Database\Model;

class User extends Model
{
    // Table name (set explicitly; used by query(), save(), etc.)
    protected ?string $tableName = 'users';

    // Mass-assignable fields (fill() and create() filter by this list)
    protected array $fillable = [
        'name', 'email', 'password', 'role', 'is_active',
    ];

    // Attribute casting
    protected array $casts = [
        'is_active' => 'bool',
        'settings'  => 'json',
        'age'       => 'int',
    ];

    // Timestamps (enabled by default)
    protected bool $timestamps = true;
    protected string $createdAt = 'created_at';
    protected string $updatedAt = 'updated_at';
}
```

The primary key defaults to `id` (change it with `setPrimaryKey()` / read it with `getPrimaryKey()`). Supported cast types: `int`/`integer`, `float`/`double`/`real`, `string`, `bool`/`boolean`, `array`/`json` (JSON strings are decoded to arrays).

## CRUD operations

```php
// CREATE — attribute style
$user = new User();
$user->name = 'John Doe';
$user->email = 'john@example.com';
$user->password = password_hash('secret', PASSWORD_DEFAULT);
$user->save();                      // insert (no id yet) — id is set on the model after

// CREATE — mass assignment; returns the new record's ID
$userId = User::create([
    'name'     => 'John Doe',
    'email'    => 'john@example.com',
    'password' => password_hash('secret', PASSWORD_DEFAULT),
]);

// READ
$user  = User::findModel(1);                                       // Model or null
$row   = User::find(1);                                            // raw array or null
$rows  = User::all();                                              // raw arrays
$user  = User::where('email', '=', 'john@example.com')->firstModel();  // Model
$row   = User::where('email', '=', 'john@example.com')->first();       // raw array
$users = User::where('is_active', '=', 1)->getModels();                // Models

// UPDATE
$user = User::findModel(1);
$user->name = 'Jane Doe';
$user->save();                      // update (id present)

// Update by primary key without loading
$user->updateById(1, ['role' => 'admin']);

// Update via query (bulk)
User::where('role', '=', 'guest')->update(['is_active' => 0]);

// DELETE
$user = User::findModel(1);
$user->delete();                    // fires deleting/deleted events

// Or via query / by id
User::where('is_active', '=', 0)->delete();
(new User())->deleteById(42);
```

`save()` inserts when the primary key attribute is absent and updates when it is present. With timestamps enabled, `save()` and `create()` maintain `created_at`/`updated_at` automatically.

## Mass assignment

`fill()` assigns many attributes at once, filtered by `$fillable` (when non-empty), and runs each value through its mutator:

```php
$user = new User();
$user->fill($request->all());   // only fillable keys are kept
$user->save();
```

`create()` and `updateById()` apply the same `$fillable` filter.

## Accessors & mutators

Define `get{Studly}Attribute()` / `set{Studly}Attribute()` methods. Accessors intercept property reads; mutators transform the value on write and must **return** the value to store:

```php
class User extends Model
{
    // Called when reading $user->name
    public function getNameAttribute(): string
    {
        return ucfirst($this->attributes['name']);
    }

    // Called when setting $user->password — return the stored value
    public function setPasswordAttribute(string $value): string
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }
}

$user->password = 'plain_text';   // stored hashed
echo $user->name;                 // ucfirst'd on read
```

Snake_case attributes map to StudlyCase methods (`first_name` → `getFirstNameAttribute`).

## Query scopes

Define `scope{Name}` methods; call them statically to start a chain or fluently mid-chain. The query instance is passed as the first argument:

```php
class User extends Model
{
    public function scopeActive($query): void
    {
        $query->where('is_active', '=', 1);
    }

    public function scopeRole($query, string $role): void
    {
        $query->where('role', '=', $role);
    }

    public function scopeRecent($query, int $days = 7): void
    {
        $query->where('created_at', '>=', date('Y-m-d', strtotime("-{$days} days")));
    }
}

// Chain scopes fluently
$admins = User::active()->role('admin')->recent(30)->getModels();
```

## Model events

Eight lifecycle events fire around persistence: `creating`, `created`, `updating`, `updated`, `saving`, `saved`, `deleting`, `deleted`. Register listeners with the static methods of the same name — each takes a callable receiving the model (and an optional hook priority, default `10`). Returning `false` from a *before* event (`creating`, `updating`, `saving`, `deleting`) cancels the operation:

```php
use System\Security\Crypto;

User::creating(function (User $user) {
    $user->uuid = Crypto::randomHex(16);
});

User::updated(function (User $user) {
    // e.g. bust a cache entry
});

User::deleting(function (User $user) {
    if ($user->role === 'admin') {
        return false;   // cancel the delete
    }
});
```

Events are dispatched through the [Hooks](/features/hooks) system under the name `model.{ClassName}.{event}`, so they are also observable from plugins.

- `save()` fires `saving` → `creating`/`updating` → `created`/`updated` → `saved`.
- `delete()` fires `deleting` → `deleted` (only when the model has a primary key value).

## Serialization

Models implement `JsonSerializable` and `ArrayAccess`:

```php
$user = User::findModel(1);

$array = $user->toArray();     // attributes (casts applied) + loaded relations
$json  = json_encode($user);   // uses jsonSerialize() => toArray()

$user['email'];                // ArrayAccess read (accessors/casts apply)
```

`toArray()` includes eager-loaded relations, recursively converting related models and collections.

::: warning Gotchas
- Bulk operations (`User::where(...)->update()` / `->delete()`, `updateById()`, `deleteById()`) do **not** fire model events — only instance `save()` and `delete()` do.
- `create()` bypasses accessors/mutators (it inserts the given array directly, after the `$fillable` filter and timestamps). Use `fill()` + `save()` when mutators must run.
- Casts apply on **read** (`__get`, `toArray()`), not when writing to the database.
- Calling an undefined method on a model instance throws `BadMethodCallException` after scope resolution fails.
:::

## Related

- [Relationships](/database/relationships)
- [Query Builder](/database/query-builder)
- [Pagination](/database/pagination)
- [Hooks](/features/hooks)
- [API Reference: Model](/api/model)
