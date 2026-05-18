<?php
declare(strict_types=1);

namespace System\Database;

use System\Database\Relations\BelongsToMany;

/**
 * Model
 *
 * Thin convenience layer above MasterModel + QueryBuilder.
 *
 * Supports:
 *  - Mass assignment (fillable)
 *  - Attribute casting
 *  - Timestamps (created_at, updated_at)
 *  - Relationships (hasOne, hasMany, belongsTo, belongsToMany)
 *  - Eager loading
 *  - Query scopes (local scopes via scope{Name} methods)
 *  - Accessors & Mutators (get{Key}Attribute / set{Key}Attribute)
 *  - Model events (creating, created, updating, updated, saving, saved, deleting, deleted)
 *  - Pagination (paginate, simplePaginate)
 */
class Model extends MasterModel implements \ArrayAccess, \JsonSerializable
{
    use ModelEventTrait;

    /** @var array<int,string> */
    protected array $fillable = [];

    /** @var array<string,string> */
    protected array $casts = [];

    /** @var string|null Table name override (child classes set this) */
    protected ?string $tableName = null;

    protected bool $timestamps = true;
    protected string $createdAt = 'created_at';
    protected string $updatedAt = 'updated_at';

    public array $attributes = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct();
        $this->attributes = $attributes;

        // Auto-set table if $table or $tableName property exists in child class
        // Use $tableName if provided, otherwise fallback to $table
        $name = $this->tableName ?? (property_exists($this, 'table') ? $this->table : null);
        if ($name && $name !== '' && !str_contains((string)$name, '`')) {
            $this->table((string)$name);
        }
    }

    /**
     * Hydrate a model instance from a database row (array).
     */
    public static function hydrate(array $attributes): static
    {
        return new static($attributes);
    }

    /* =====================================================
     | Accessors & Mutators (B2)
     | ===================================================== */

    public function __get(string $key)
    {
        // 1. Check loaded relations
        if (array_key_exists($key, $this->relationsLoaded)) {
            return $this->relationsLoaded[$key];
        }

        // 2. Check for accessor: get{Key}Attribute()
        $accessor = 'get' . self::studly($key) . 'Attribute';
        if (method_exists($this, $accessor)) {
            return $this->$accessor();
        }

        // 3. Normal attribute access with casting
        $value = $this->attributes[$key] ?? null;
        return $this->hasCast($key) ? $this->castAttribute($key, $value) : $value;
    }

    public function __set(string $key, mixed $value)
    {
        // Check for mutator: set{Key}Attribute()
        $mutator = 'set' . self::studly($key) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $value = $this->$mutator($value);
        }

        $this->attributes[$key] = $value;
    }

    /* =====================================================
     | Query Scopes (B1)
     | ===================================================== */

    /**
     * Static magic method to forward calls to new query instance.
     * Allows Model::where(...) and Model::active() calling style.
     *
     * Scope resolution:
     *   Model::active()  → looks for scopeActive() method
     *   Model::recent(7) → looks for scopeRecent(QueryBuilder $q, int $days) method
     */
    public static function __callStatic(string $method, array $args)
    {
        $instance = static::query();

        // Check for scope method: scope{Method}
        $scopeMethod = 'scope' . ucfirst($method);
        if (method_exists($instance, $scopeMethod)) {
            $instance->$scopeMethod($instance, ...$args);
            return $instance;
        }

        return $instance->$method(...$args);
    }

    /**
     * Instance magic method for scope chaining.
     *
     * Allows: $query->active()->recent(7)->orderBy('name')
     */
    public function __call(string $method, array $args): mixed
    {
        // Check for scope method: scope{Method}
        $scopeMethod = 'scope' . ucfirst($method);
        if (method_exists($this, $scopeMethod)) {
            $this->$scopeMethod($this, ...$args);
            return $this;
        }

        // Fall through to QueryBuilder methods
        throw new \BadMethodCallException("Method [{$method}] does not exist on " . static::class . ".");
    }

    /* =====================================================
     | Mass Assignment
     | ===================================================== */

    /**
     * Mass assign attributes, filtering by fillable.
     */
    public function fill(array $attributes): static
    {
        if (!empty($this->fillable)) {
             $attributes = array_intersect_key($attributes, array_flip($this->fillable));
        }

        foreach ($attributes as $key => $value) {
            $this->__set($key, $value); // Use __set to apply mutators
        }
        return $this;
    }

    /* =====================================================
     | Save / Delete with Model Events (B3)
     | ===================================================== */

    /**
     * Save the model to the database.
     * Inserts if no ID exists, updates if ID exists.
     * Fires model events: saving, creating/updating, created/updated, saved.
     */
    public function save(): bool
    {
        // Fire "saving" event (before any save, create or update)
        if (!$this->fireModelEvent('saving')) {
            return false;
        }

        $pk = $this->getPrimaryKey();
        $id = $this->attributes[$pk] ?? null;

        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');
            $this->attributes[$this->updatedAt] = $now;

            if (!$id && !isset($this->attributes[$this->createdAt])) {
                $this->attributes[$this->createdAt] = $now;
            }
        }

        if ($id) {
            // Update
            if (!$this->fireModelEvent('updating')) {
                return false;
            }

            $data = $this->attributes;
            unset($data[$pk]);

            $result = (bool) static::query($this->table)
                ->where($pk, '=', $id)
                ->update($data);

            if ($result) {
                $this->fireModelEvent('updated');
            }
        } else {
            // Insert
            if (!$this->fireModelEvent('creating')) {
                return false;
            }

            $this->insert($this->attributes);
            $newId = $this->lastInsertId();

            if ($newId) {
                $numericId = is_numeric($newId) ? (int)$newId : $newId;
                $this->attributes[$pk] = $numericId;
                $result = true;

                $this->fireModelEvent('created');
            } else {
                $result = false;
            }
        }

        if ($result) {
            $this->fireModelEvent('saved');
        }

        return $result;
    }

    /**
     * Delete the current model instance.
     * Fires model events: deleting, deleted.
     */
    public function delete(): int
    {
        $pk = $this->getPrimaryKey();
        $id = $this->attributes[$pk] ?? null;

        if (!$id) {
            return parent::delete();
        }

        if (!$this->fireModelEvent('deleting')) {
            return 0;
        }

        $count = $this->deleteById($id);

        if ($count > 0) {
            $this->fireModelEvent('deleted');
        }

        return $count;
    }

    /* =====================================================
     | Serialization
     | ===================================================== */

    /**
     * Convert model attributes to array.
     */
    public function toArray(): array
    {
        $data = [];
        // Attributes
        foreach ($this->attributes as $key => $value) {
            $data[$key] = $this->hasCast($key) ? $this->castAttribute($key, $value) : $value;
        }
        // Relations
        foreach ($this->relationsLoaded as $key => $value) {
            if ($value instanceof Model) {
                $data[$key] = $value->toArray();
            } elseif ($value instanceof \System\Support\Collection) {
                $data[$key] = $value->toArray();
            } elseif (is_array($value)) {
                $data[$key] = array_map(fn($item) => $item instanceof Model ? $item->toArray() : $item, $value);
            } else {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /* =====================================================
     | Casting Logic
     | ===================================================== */

    protected function hasCast(string $key): bool
    {
        return isset($this->casts[$key]);
    }

    protected function castAttribute(string $key, mixed $value)
    {
        if ($value === null) return null;

        $type = strtolower(trim($this->casts[$key]));

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => is_string($value) ? json_decode($value, true) : (array)$value,
            default => $value,
        };
    }

    /* =====================================================
     | ArrayAccess Implementation
     | ===================================================== */

    public function offsetExists(mixed $offset): bool {
        return array_key_exists($offset, $this->attributes) || array_key_exists($offset, $this->relationsLoaded);
    }

    public function offsetGet(mixed $offset): mixed {
        return $this->__get((string)$offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        $this->__set((string)$offset, $value);
    }

    public function offsetUnset(mixed $offset): void {
        unset($this->attributes[$offset]);
    }

    /* =====================================================
     | Relationships
     | ===================================================== */

    /**
     * Define relationships declaratively.
     * return [
     *     'posts'  => ['hasMany', Post::class, 'user_id', 'id'],
     *     'roles'  => ['belongsToMany', Role::class, 'user_roles', 'user_id', 'role_id'],
     * ]
     */
    protected static function relations(): array { return []; }

    /** @var string[] Instance-level eager load config (not static, safe for concurrent use) */
    protected array $pendingEagerLoad = [];

    /**
     * Eager-load relationships to avoid N+1 queries.
     */
    public static function withRelations(string ...$relations): static
    {
        $instance = static::query();
        $instance->pendingEagerLoad = $relations;
        return $instance;
    }

    protected static function eagerLoad(array $models, array $includes = []): void
    {
        if (empty($includes) || empty($models)) return;

        $schema = static::relations();

        foreach ($includes as $name) {
            if (!isset($schema[$name])) continue;

            $def = $schema[$name];
            $type = $def[0];

            if ($type === 'belongsToMany') {
                // belongsToMany eager loading
                [$type, $relatedClass, $pivotTable, $foreignPivotKey, $relatedPivotKey] = $def;
                $parentKey = $def[5] ?? 'id';
                $relatedKey = $def[6] ?? 'id';

                // Collect parent IDs
                $parentIds = [];
                foreach ($models as $m) {
                    $pid = $m->attributes[$parentKey] ?? null;
                    if ($pid !== null) $parentIds[] = $pid;
                }
                $parentIds = array_unique($parentIds);
                if (empty($parentIds)) continue;

                // Batch query the pivot table on the parent model's connection
                $db = $models[0]->resolveConnection();
                $placeholders = implode(', ', array_fill(0, count($parentIds), '?'));
                $pivotRows = $db->fetchAll(
                    "SELECT `{$foreignPivotKey}`, `{$relatedPivotKey}` FROM `{$pivotTable}` WHERE `{$foreignPivotKey}` IN ({$placeholders})",
                    array_values($parentIds)
                );

                // Collect all related IDs
                $allRelatedIds = array_unique(array_column($pivotRows, $relatedPivotKey));
                if (empty($allRelatedIds)) {
                    foreach ($models as $m) {
                        $m->setRelation($name, new \System\Support\Collection());
                    }
                    continue;
                }

                // Fetch all related models in one query
                $relatedModels = (new $relatedClass)->whereIn($relatedKey, $allRelatedIds)->getModels();
                $relatedMap = [];
                foreach ($relatedModels as $rm) {
                    $relatedMap[$rm->attributes[$relatedKey]] = $rm;
                }

                // Build mapping: parent_id => [related models]
                $pivotMap = [];
                foreach ($pivotRows as $pr) {
                    $pid = $pr[$foreignPivotKey];
                    $rid = $pr[$relatedPivotKey];
                    if (isset($relatedMap[$rid])) {
                        $pivotMap[$pid][] = $relatedMap[$rid];
                    }
                }

                // Assign to models
                foreach ($models as $m) {
                    $pid = $m->attributes[$parentKey] ?? null;
                    $m->setRelation($name, new \System\Support\Collection($pivotMap[$pid] ?? []));
                }

                continue;
            }

            // Standard relationships: hasOne, hasMany, belongsTo
            [$type, $relatedClass, $foreignKey, $localKey] = $def;

            // 1. Collect IDs
            $ids = [];
            foreach ($models as $m) $ids[] = $m->attributes[$localKey] ?? null;
            $ids = array_filter(array_unique($ids));
            if (empty($ids)) continue;

            if ($type === 'belongsTo') {
                 $ids = [];
                 foreach ($models as $m) $ids[] = $m->attributes[$foreignKey] ?? null;
                 $ids = array_filter(array_unique($ids));
                 if (empty($ids)) continue;

                 $relatedInstances = (new $relatedClass)->whereIn($localKey, $ids)->getModels();

                 $map = [];
                 foreach ($relatedInstances as $r) {
                     $k = $r->attributes[$localKey];
                     $map[$k] = $r;
                 }

                 foreach ($models as $m) {
                     $fk = $m->attributes[$foreignKey] ?? null;
                     if ($fk && isset($map[$fk])) {
                         $m->setRelation($name, $map[$fk]);
                     }
                 }

            } else {
                 $relatedInstances = (new $relatedClass)->whereIn($foreignKey, $ids)->getModels();

                 $map = [];
                 foreach ($relatedInstances as $r) {
                     $k = $r->attributes[$foreignKey];
                     $map[$k][] = $r;
                 }

                 foreach ($models as $m) {
                     $id = $m->attributes[$localKey] ?? null;
                     if ($type === 'hasOne') {
                         $m->setRelation($name, $map[$id][0] ?? null);
                     } else {
                         $m->setRelation($name, $map[$id] ?? []);
                     }
                 }
            }
        }
    }

    private array $relationsLoaded = [];
    public function setRelation(string $name, mixed $value): void
    {
        $this->relationsLoaded[$name] = $value;
    }

    /**
     * Define a one-to-one relationship.
     */
    public function hasOne(string $related, string $foreignKey, string $localKey = 'id'): MasterModel
    {
        $instance = new $related();
        $localValue = $this->attributes[$localKey] ?? null;
        return $instance->where($foreignKey, '=', $localValue);
    }

    /**
     * Define a one-to-many relationship.
     */
    public function hasMany(string $related, string $foreignKey, string $localKey = 'id'): MasterModel
    {
        $instance = new $related();
        $localValue = $this->attributes[$localKey] ?? null;
        return $instance->where($foreignKey, '=', $localValue);
    }

    /**
     * Define an inverse one-to-one or many-to-one relationship.
     */
    public function belongsTo(string $related, string $foreignKey, string $ownerKey = 'id'): MasterModel
    {
        $instance = new $related();
        $foreignValue = $this->attributes[$foreignKey] ?? null;
        return $instance->where($ownerKey, '=', $foreignValue);
    }

    /**
     * Define a many-to-many relationship (B4).
     *
     * Returns a BelongsToMany instance for querying and managing the pivot table.
     *
     * Usage:
     *   $user->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
     *
     * @param string $related         FQCN of the related model.
     * @param string $pivotTable      Pivot/junction table name.
     * @param string $foreignPivotKey Foreign key for this model on the pivot table.
     * @param string $relatedPivotKey Foreign key for the related model on the pivot table.
     * @param string $parentKey       Primary key on this model (default: 'id').
     * @param string $relatedKey      Primary key on the related model (default: 'id').
     */
    public function belongsToMany(
        string $related,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey = 'id',
        string $relatedKey = 'id'
    ): BelongsToMany {
        return new BelongsToMany(
            $this,
            $related,
            $pivotTable,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey
        );
    }

    /* =====================================================
     | Query Chain
     | ===================================================== */

    /**
     * Start a query chain.
     */
    public static function query(string $table = ''): static
    {
        $model = new static();

        // Ensure a clean builder state for this query entry-point
        $model->reset();

        // If table passed, use it; otherwise rely on tableName set by constructor
        if ($table !== '') {
            $model->table($table);
        } elseif (property_exists($model, 'tableName') && !empty($model->tableName)) {
            $model->table($model->tableName);
        }

        return $model;
    }

    /**
     * Fetch all records as raw arrays.
     * @return array<int,array<string,mixed>>
     */
    public function get(): array
    {
        return parent::get();
    }

    /**
     * Fetch all records as hydrated Models.
     * @return array<int,static>
     */
    public function getModels(): array
    {
        $results = parent::get();
        return static::hydrateAndLoad($results, $this->pendingEagerLoad);
    }

    /**
     * Fetch first record as raw array.
     */
    public function first(): ?array
    {
        return parent::first();
    }

    /**
     * Fetch first record as hydrated Model.
     */
    public function firstModel(): ?static
    {
        $result = parent::first();
        if ($result && is_array($result)) {
            $models = static::hydrateAndLoad([$result], $this->pendingEagerLoad);
            return $models[0] ?? null;
        }
        return null;
    }

    /**
     * Fetch all records as raw arrays.
     */
    public static function all(): array
    {
        return (new static)->get();
    }

    /**
     * Helper to manually hydrate and eager load a set of raw rows.
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,static>
     */
    public static function hydrateAndLoad(array $rows, array $eagerLoadRelations = []): array
    {
        if (empty($rows)) return [];

        // 1. Hydrate
        $models = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $models[] = static::hydrate($row);
            }
        }

        // 2. Eager Load
        if (!empty($models) && !empty($eagerLoadRelations)) {
            static::eagerLoad($models, $eagerLoadRelations);
        }

        return $models;
    }

    /** @return array<string,mixed>|null Returns a raw row array, or null if not found */
    public function find(mixed $id): ?array
    {
        $clone = clone $this;
        $clone->where($this->getPrimaryKey(), '=', $id);
        return $clone->first();
    }

    /** @return static|null */
    public function findModel(mixed $id): ?static
    {
        $clone = clone $this;
        $clone->where($this->getPrimaryKey(), '=', $id);
        return $clone->firstModel();
    }

    /**
     * Create a new record and return the ID.
     * @param array<string,mixed> $data
     */
    public function create(array $data): int|string
    {
        // Filter by fillable if defined
        if (!empty($this->fillable)) {
             $data = array_intersect_key($data, array_flip($this->fillable));
        }

        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');
            if (!isset($data[$this->createdAt])) $data[$this->createdAt] = $now;
            if (!isset($data[$this->updatedAt])) $data[$this->updatedAt] = $now;
        }

        $this->insert($data);
        $id = $this->lastInsertId();
        if ($id === false) {
             return 0;
        }
        return is_numeric($id) ? (int)$id : $id;
    }

    /**
     * Update a record by its primary key.
     * @param int|string $id
     * @param array<string,mixed> $data
     */
    public function updateById(int|string $id, array $data): bool
    {
        // Filter by fillable
        if (!empty($this->fillable)) {
             $data = array_intersect_key($data, array_flip($this->fillable));
        }

        if ($this->timestamps) {
            $data[$this->updatedAt] = date('Y-m-d H:i:s');
        }

        if (empty($data)) {
            return true;
        }

        // Use a fresh query for the update to avoid mutating the current model state
        return (bool) static::query($this->table)
            ->where($this->getPrimaryKey(), '=', $id)
            ->update($data);
    }

    /**
     * Delete a record by its primary key.
     * @param int|string $id
     */
    public function deleteById(int|string $id): int
    {
        return static::query($this->table)
            ->where($this->getPrimaryKey(), '=', $id)
            ->delete();
    }

    /* =====================================================
     | Internal Helpers
     | ===================================================== */

    /**
     * Convert a snake_case string to StudlyCase.
     * e.g. "first_name" → "FirstName", "full_name" → "FullName"
     */
    private static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $value)));
    }
}
