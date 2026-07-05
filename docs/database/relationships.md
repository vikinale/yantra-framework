# Relationships

Yantra models support one-to-one, one-to-many, inverse, and many-to-many relationships. Relationship methods return **query objects** that you execute explicitly with `firstModel()` / `getModels()`; property-style access (`$user->posts`) works only for relations that were eager loaded.

```php
$user  = User::findModel(1);
$posts = $user->posts()->getModels();   // execute the relation query
```

## Defining relationships

Foreign keys are **required, explicit arguments** — there is no key-name convention or auto-guessing:

```php
use System\Database\Model;
use System\Database\Relations\BelongsToMany;

class User extends Model
{
    protected ?string $tableName = 'users';

    // One-to-one — hasOne(related, foreignKey, localKey = 'id')
    public function profile()
    {
        return $this->hasOne(Profile::class, 'user_id');
    }

    // One-to-many — hasMany(related, foreignKey, localKey = 'id')
    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }

    // Inverse — belongsTo(related, foreignKey, ownerKey = 'id')
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    // Many-to-many — belongsToMany(related, pivotTable, foreignPivotKey,
    //                              relatedPivotKey, parentKey = 'id', relatedKey = 'id')
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }
}
```

## Querying relationships

`hasOne()`, `hasMany()`, and `belongsTo()` return a query builder pre-filtered on the relation keys. Chain further constraints, then execute:

```php
$user = User::findModel(1);

$profile = $user->profile()->firstModel();   // Profile or null
$posts   = $user->posts()->getModels();      // array of Post models
$company = $user->company()->firstModel();   // Company or null

// Add constraints before executing
$published = $user->posts()
    ->where('published', '=', 1)
    ->orderBy('created_at', 'DESC')
    ->getModels();

// Raw arrays also work, exactly like any query
$rows = $user->posts()->get();
```

## Many-to-many and pivot management

`belongsToMany()` returns a `System\Database\Relations\BelongsToMany` object, which both fetches related models and manages the pivot table:

```php
$user = User::findModel(1);

// Fetch related models (returns a Collection of Role models)
$roles = $user->roles()->get();

// Pivot management
$user->roles()->attach([1, 2, 3]);                       // add pivot rows (INSERT IGNORE)
$user->roles()->attach([4], ['granted_by' => 7]);        // with extra pivot columns
$user->roles()->detach([2]);                             // remove specific pivot rows
$user->roles()->detach();                                // detach all
$user->roles()->sync([1, 3, 5]);                         // make pivot match exactly
$user->roles()->sync([1, 3], ['granted_by' => 7]);       // extra columns for new rows

$user->roles()->contains(3);   // bool — is this related id attached?
$user->roles()->count();       // int — number of pivot rows
```

`sync()` diffs against the current pivot rows: it detaches ids no longer in the list and attaches new ones (extra attributes apply to newly attached rows only).

## Eager loading

Eager loading prevents N+1 queries by batch-fetching relations for a whole result set. It is driven by the **declarative `relations()` schema** — a static method returning a map of relation name to definition:

```php
class User extends Model
{
    protected ?string $tableName = 'users';

    protected static function relations(): array
    {
        return [
            // name => [type, relatedClass, foreignKey, localKey]
            'posts'   => ['hasMany',  Post::class,    'user_id',    'id'],
            'profile' => ['hasOne',   Profile::class, 'user_id',    'id'],
            'company' => ['belongsTo', Company::class, 'company_id', 'id'],

            // name => [type, relatedClass, pivotTable, foreignPivotKey, relatedPivotKey(, parentKey, relatedKey)]
            'roles'   => ['belongsToMany', Role::class, 'user_roles', 'user_id', 'role_id'],
        ];
    }
}
```

Then request relations with `withRelations()` — a static method taking **variadic relation names** — at the start of the chain, and execute with `getModels()` (or `firstModel()`):

```php
// One query for users + one per relation, instead of N+1
$users = User::withRelations('posts', 'profile')
    ->where('is_active', '=', 1)
    ->getModels();

foreach ($users as $user) {
    $user->posts;     // array of Post models (already loaded, no query)
    $user->profile;   // Profile model or null
}

$user = User::withRelations('roles')->where('id', '=', 1)->firstModel();
$user->roles;         // Collection of Role models
```

Loaded relation values by type:

| Type | Property value after eager loading |
| --- | --- |
| `hasOne` | related model or `null` |
| `hasMany` | array of related models |
| `belongsTo` | related model (unset when no match) |
| `belongsToMany` | `Collection` of related models |

You can also hydrate and eager-load rows you fetched yourself with `User::hydrateAndLoad($rows, ['posts'])`, or attach a value manually with `$model->setRelation('name', $value)`.

::: warning Gotchas
- **Property access only works for eager-loaded relations.** `$user->posts` returns `null` (as a missing attribute) unless `posts` was loaded via `withRelations()` — it does *not* lazily run the `posts()` method. Use `$user->posts()->getModels()` for on-demand fetching.
- `withRelations()` takes variadic strings — `User::withRelations('posts', 'profile')`, **not** an array.
- Call `withRelations()` **first** in the chain. It is a static method that starts a fresh query, so constraints added before it are discarded.
- Eager loading resolves names against the `relations()` schema. A name missing from that schema is silently skipped — defining a `posts()` method alone is not enough for `withRelations('posts')`.
- `BelongsToMany::get()` returns a `Collection`, while an eager-loaded `hasMany` property is a plain array — don't assume the same type.
:::

## Related

- [Models & ORM](/database/models)
- [Query Builder](/database/query-builder)
- [Collections](/features/collections)
- [API Reference: Model](/api/model)
