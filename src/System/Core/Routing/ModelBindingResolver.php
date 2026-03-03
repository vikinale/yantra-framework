<?php
declare(strict_types=1);

namespace System\Core\Routing;

use System\Database\Model;
use System\Http\Exceptions\HttpException;

/**
 * ModelBindingResolver — Resolves route parameters to Model instances.
 *
 * Provides automatic route model binding: when a controller method type-hints a
 * parameter as a Model subclass, the resolver automatically fetches the model
 * from the database using the route parameter value (typically an ID).
 *
 * Supports:
 *  - Implicit binding (type-hint based)
 *  - Explicit binding (manual registration of parameter → model class mapping)
 *  - Custom key columns (e.g., bind by 'slug' instead of primary key)
 *
 * Usage:
 *   // Implicit: controller type-hint resolves automatically
 *   public function show(Request $req, Response $res, User $user): Response
 *
 *   // Explicit: register custom binding
 *   ModelBindingResolver::bind('user', User::class);
 *   ModelBindingResolver::bind('post', Post::class, 'slug');
 *
 * If a model is not found, a 404 HttpException is thrown automatically.
 */
final class ModelBindingResolver
{
    /**
     * Explicit bindings: parameter name → [model class, column].
     * @var array<string, array{class: string, column: string|null}>
     */
    private static array $bindings = [];

    /**
     * Register an explicit model binding.
     *
     * @param string      $parameter The route parameter name (e.g. 'user', 'post').
     * @param string      $modelClass FQCN of the Model subclass.
     * @param string|null $column    Column to query by (null = primary key).
     */
    public static function bind(string $parameter, string $modelClass, ?string $column = null): void
    {
        self::$bindings[$parameter] = [
            'class'  => $modelClass,
            'column' => $column,
        ];
    }

    /**
     * Check if an explicit binding exists for a parameter.
     */
    public static function hasBinding(string $parameter): bool
    {
        return isset(self::$bindings[$parameter]);
    }

    /**
     * Get the explicit binding for a parameter.
     * @return array{class: string, column: string|null}|null
     */
    public static function getBinding(string $parameter): ?array
    {
        return self::$bindings[$parameter] ?? null;
    }

    /**
     * Clear all registered bindings (useful for testing).
     */
    public static function clearBindings(): void
    {
        self::$bindings = [];
    }

    /**
     * Resolve a route parameter to a Model instance.
     *
     * Checks explicit bindings first, then falls back to implicit (type-hint) resolution.
     *
     * @param string      $paramName   The route parameter name (e.g. 'user').
     * @param mixed       $paramValue  The route parameter value (e.g. '5', 'john-doe').
     * @param string|null $typeHint    FQCN from the controller method type-hint (for implicit).
     * @return Model|null  The resolved model, or null if binding doesn't apply.
     *
     * @throws HttpException 404 if the model is not found.
     */
    public static function resolve(string $paramName, mixed $paramValue, ?string $typeHint = null): ?Model
    {
        // 1. Check explicit binding
        if (isset(self::$bindings[$paramName])) {
            $binding = self::$bindings[$paramName];
            $modelClass = $binding['class'];
            $column = $binding['column'];

            return self::findOrFail($modelClass, $paramValue, $column);
        }

        // 2. Implicit binding via type-hint
        if ($typeHint !== null && class_exists($typeHint) && is_subclass_of($typeHint, Model::class)) {
            return self::findOrFail($typeHint, $paramValue, null);
        }

        // Not a model binding
        return null;
    }

    /**
     * Find a model by its value or throw 404.
     *
     * @param string      $modelClass FQCN of the Model.
     * @param mixed       $value      The lookup value.
     * @param string|null $column     The column to query (null = primary key).
     * @return Model
     *
     * @throws HttpException If model not found.
     */
    private static function findOrFail(string $modelClass, mixed $value, ?string $column): Model
    {
        /** @var Model $instance */
        $instance = new $modelClass();

        if ($column === null) {
            // Use primary key
            $model = $instance->findModel($value);
        } else {
            // Use custom column
            $model = $instance->where($column, '=', $value)->firstModel();
        }

        if ($model === null) {
            $shortName = class_exists($modelClass) ?
                (str_contains($modelClass, '\\') ? substr($modelClass, strrpos($modelClass, '\\') + 1) : $modelClass)
                : 'Model';

            throw new HttpException(404, "{$shortName} not found.");
        }

        return $model;
    }
}
