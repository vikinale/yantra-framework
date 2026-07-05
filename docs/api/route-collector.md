# API Reference: RouteCollector

`System\Core\Routing\RouteCollector`

The route registrar. You declare routes on a `RouteCollector` (usually inside your routes file), and each registration returns a small fluent definition object so you can attach middleware and names. The collector normalizes paths, tracks a group prefix/middleware stack, and stores the compiled route list that the [`Router`](/essentials/routing) later matches against. For a guide, see [Routing](/essentials/routing).

```php
$r->get('/users/{id}', [UserController::class, 'show'])->name('users.show');

$r->group('/admin', function (RouteCollector $r): void {
    $r->get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
})->middleware('auth', ['roles' => 'admin'])->name('admin.');
```

## Method Table

### HTTP verb registration

Each returns a [`RouteDefinition`](#chaining-routedefinition) (single route) unless noted.

| Signature | Returns | Description |
| --- | --- | --- |
| `get(string $path, mixed $handler): RouteDefinition` | `RouteDefinition` | Register a `GET` route. |
| `post(string $path, mixed $handler): RouteDefinition` | `RouteDefinition` | Register a `POST` route. |
| `put(string $path, mixed $handler): RouteDefinition` | `RouteDefinition` | Register a `PUT` route. |
| `patch(string $path, mixed $handler): RouteDefinition` | `RouteDefinition` | Register a `PATCH` route. |
| `delete(string $path, mixed $handler): RouteDefinition` | `RouteDefinition` | Register a `DELETE` route. |
| `options(string $path, mixed $handler): RouteDefinition` | `RouteDefinition` | Register an `OPTIONS` route. |
| `match(array $methods, string $path, mixed $handler): GroupDefinition` | `GroupDefinition` | Register the same route for several methods; fluent calls apply to all. |
| `any(string $path, mixed $handler): GroupDefinition` | `GroupDefinition` | Register for `GET`/`POST`/`PUT`/`PATCH`/`DELETE`/`OPTIONS`. |

`$handler` is typically `[ControllerClass::class, 'method']`.

### Groups & resources

| Signature | Returns | Description |
| --- | --- | --- |
| `group(string $prefix, callable $callback): GroupDefinition` | `GroupDefinition` | Register routes under a shared prefix; the callback receives the collector. |
| `resource(string $name, string $controller, array $options = []): ResourceDefinition` | `ResourceDefinition` | Seven RESTful routes (index/create/store/show/edit/update/destroy). |
| `apiResource(string $name, string $controller, array $options = []): ResourceDefinition` | `ResourceDefinition` | Five routes (omits `create`/`edit` form actions). |

### Error handlers

| Signature | Returns | Description |
| --- | --- | --- |
| `error(int $code, mixed $handler): void` | `void` | Register a handler for a 4xx/5xx status (code must be 400–599). |

### Reading / clearing

| Signature | Returns | Description |
| --- | --- | --- |
| `getRoutes(): array` | `array` | The collected route definitions. |
| `getErrors(): array` | `array` | The registered error handlers. |
| `clear(): void` | `void` | Reset all routes, errors, and the group stack. |

> The `_`-prefixed methods (`_buildMiddlewareAdd`, `_applyMiddlewareToRouteRange`, `_setName`, …) are internal bridges used by the fluent definition objects. Don't call them directly.

## Resource routes

`resource('users', UserController::class)` generates:

| Verb | Path | Action | Name |
| --- | --- | --- | --- |
| `GET` | `/users` | `index` | `users.index` |
| `GET` | `/users/create` | `create` | `users.create` |
| `POST` | `/users` | `store` | `users.store` |
| `GET` | `/users/{user}` | `show` | `users.show` |
| `GET` | `/users/{user}/edit` | `edit` | `users.edit` |
| `PUT` | `/users/{user}` | `update` | `users.update` |
| `DELETE` | `/users/{user}` | `destroy` | `users.destroy` |

The `{user}` parameter is the singularized resource name (`users` → `user`, `categories` → `category`); override it with `options['parameter']`. Filter actions with `options['only']` / `options['except']`, or use the fluent `->only()`/`->except()` on the returned `ResourceDefinition`. `apiResource()` is `resource()` with `create`/`edit` excluded.

## Chaining: definition objects

Registration methods return one of three fluent objects. Every method returns `self`, so calls chain.

### RouteDefinition

Returned by `get()`/`post()`/`put()`/`patch()`/`delete()`/`options()`.

| Method | Description |
| --- | --- |
| `middleware(array\|string $mw, array $params = []): self` | Attach middleware to this route. |
| `name(string $name): self` | Assign a route name. |

### GroupDefinition

Returned by `group()`, `match()`, and `any()`.

| Method | Description |
| --- | --- |
| `middleware(array\|string $mw, array $params = []): self` | Apply middleware to every route in the group range (and any error handlers declared inside the group). |
| `name(string $prefix): self` | Prefix the names of routes in the group (only routes that already have a name get the prefix). |

### ResourceDefinition

Returned by `resource()` / `apiResource()`.

| Method | Description |
| --- | --- |
| `only(array $actions): self` | Keep only the given actions. |
| `except(array $actions): self` | Drop the given actions. |
| `middleware(array\|string $mw, array $params = []): self` | Apply middleware to all resource routes. |
| `name(string $prefix): self` | Prefix all resource route names (e.g. `admin.` → `admin.users.index`). |
| `getResourceName(): string` / `getParameter(): string` | Introspection. |

## Middleware argument shapes

`middleware()` accepts several forms:

```php
->middleware('auth')                                   // single, no params
->middleware('auth', ['roles' => 'admin', 'redirect' => '/login'])
->middleware(['auth', 'limiter'])                      // list, no params
```

Middleware is deduplicated by id + params, and group/route middleware are merged parent-first.

## Route model binding — `Router::model()`

Automatic model resolution is registered on the **[`Router`](/essentials/routing)** (`System\Core\Routing\Router`), not on the collector:

```php
$router->model('user', User::class);            // resolve by primary key
$router->model('post', Post::class, 'slug');    // resolve by a specific column
```

When a route parameter name matches the bound `$parameter`, the framework loads the corresponding [`Model`](/api/model) from the database (via `ModelBindingResolver`) before invoking the controller — so a `/users/{user}` route can receive a hydrated `User` instead of a raw id. Pass a third `$column` argument to bind by something other than the primary key.

## Selected examples

### Named routes inside a prefixed, guarded group

```php
$r->group('/admin', function (RouteCollector $r): void {
    $r->get('/dashboard', [AdminController::class, 'index'])->name('dashboard');
    $r->resource('users', AdminUserController::class);
})->middleware('auth', ['roles' => 'admin'])->name('admin.');
// → 'admin.dashboard', 'admin.users.index', …, all behind the auth middleware
```

### Multi-method route

```php
$r->match(['GET', 'POST'], '/search', [SearchController::class, 'handle'])
  ->middleware('throttle');   // applies to both the GET and POST route
```

::: warning Gotchas
- **`group()`/`match()`/`any()` return a `GroupDefinition`, not a `RouteDefinition`.** Its `name()` is a **prefix** applied to already-named routes — unnamed routes in the group stay unnamed.
- **`resource()` names are auto-generated** (`{name}.{action}`); `ResourceDefinition::name()` *prepends* a prefix rather than replacing them.
- **Route model binding lives on `Router::model()`**, not on `RouteCollector`. The collector only records routes.
- **Trailing slashes are normalized away** (except root `/`), and a relative path gets a leading `/`.
- **`error()` codes must be 400–599**; anything else throws `InvalidArgumentException`.
:::

## Related

- [Routing guide](/essentials/routing)
- [Middleware](/essentials/middleware)
- [Controllers](/essentials/controllers)
- [API Reference: Model](/api/model) — used by route model binding
- [API Reference: Request](/api/request)
