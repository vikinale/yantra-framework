# Dependency Injection Container

Yantra's DI container (`System\Core\Container`, implementing `System\Contracts\ContainerInterface`) is a small, reflection-based service container. It resolves services from explicit definitions (factory closures in `config/dependencies.php`) or, when no definition exists, builds classes automatically by inspecting constructor type-hints. The `Application` creates the container during construction, loads the framework's default definitions plus your application's, and wires it into the router and controller factory — so controller constructor dependencies are injected for free.

```php
// config/dependencies.php (application)
use System\Contracts\ContainerInterface;

return [
    App\Services\Mailer::class => function (ContainerInterface $c) {
        return new App\Services\Mailer(
            (string) \System\Config::get('mail.dsn')
        );
    },
];
```

```php
// Anywhere a class is container-built, type-hints are auto-injected:
class NewsletterController extends BaseController
{
    public function __construct(private App\Services\Mailer $mailer) {}
}
```

## The Definitions File

`Application::__construct()` loads definitions in two passes:

1. The **framework defaults** from the framework's own `config/dependencies.php`.
2. Your **application's** `config/dependencies.php` (at the project base path), merged on top — so an app definition with the same id overrides the framework's.

A definitions file returns an associative array of `id => definition`. Ids are usually fully-qualified class or interface names, but any string works (the framework uses `'middleware.resolver'` and `'config.view.auto_escape'`, for example). The most common definition form is a **factory closure that receives the container**, letting it pull other services:

```php
use System\Contracts\ContainerInterface;
use System\View\ViewRenderer;

return [
    // Factory closure — invoked once, result cached (singleton)
    ViewRenderer::class => function (ContainerInterface $c) {
        return new ViewRenderer(
            [app_path('Views')],
            '.php',
            $c->get('config.view.auto_escape')   // definitions can depend on each other
        );
    },

    // Plain value definition
    'config.view.auto_escape' => function () {
        return (bool) (\System\Config::get('view.auto_escape') ?? true);
    },
];
```

The framework defaults define, among others: `ViewRenderer`, `Router`, `Database`, `LoggerInterface`, `JwtAuthMiddleware` (which fails fast with a clear error if no JWT public key is configured), and the `'middleware.resolver'` closure that maps aliases like `sec.csrf` and `auth.jwt` to middleware classes.

## Container API

The container exposes five methods (verified against `src/System/Core/Container.php`):

### `set(string $id, mixed $value): void`

Registers an already-built shared instance. `Application` uses this to bind `ConfigInterface` to the live config repository it created before the container existed.

```php
$container->set(App\Services\Clock::class, new App\Services\Clock());
```

### `addDefinitions(string|array $definitions): void`

Loads definitions from a file path (which must `return` an array) or from an array directly. Later definitions with the same id replace earlier ones (`array_merge`).

### `get(string $id): mixed`

Resolves an id. Resolution order:

1. **Instance cache** — anything previously resolved (or `set()`) is returned as-is.
2. **Definition** — if a definition exists:
   - a *callable* is invoked with the container and its result cached,
   - an *object* is cached and returned,
   - a *string that is an existing class name* is passed to `build()`,
   - any other value (scalar, array) is returned as-is.
3. **Autowiring fallback** — no definition? The id is treated as a class name and passed to `build()`.

### `has(string $id): bool`

Returns `true` if the id is a cached instance, a registered definition, **or any loadable class** (`class_exists`). That last clause means `has()` answers "could `get()` succeed?", not "was this explicitly registered?".

### `build(string $className): mixed`

Reflection-based autowiring:

- Throws a `RuntimeException` if the class doesn't exist or isn't instantiable (abstract classes, interfaces).
- With no constructor, the class is instantiated directly.
- Each constructor parameter with a **non-builtin named type-hint** is resolved recursively via `get()` — so nested dependencies are built too.
- Builtin/untyped parameters use their **default value** if one exists; otherwise a `RuntimeException` explains which parameter couldn't be resolved.
- The built instance is cached, making every autowired class a **singleton**.

```php
class ReportService
{
    public function __construct(
        private \System\Contracts\DatabaseInterface $db,  // resolved via interface binding
        private App\Services\Mailer $mailer,              // autowired or from definitions
        private int $batchSize = 100                      // builtin: default value used
    ) {}
}

$service = $container->get(ReportService::class);  // no definition needed
```

## Interface Bindings

Autowiring cannot instantiate an interface, so interfaces must be bound to concrete factories. The framework's `config/dependencies.php` ships fallback bindings for the four core contracts:

| Interface | Fallback resolution |
| --- | --- |
| `ConfigInterface` | `Config::getInstance()` |
| `DatabaseInterface` | `Database::getInstance()->getAdapter()` |
| `HooksInterface` | `Hooks::getInstance()` |
| `SessionInterface` | `SessionStore::getInstance()` |

`Application` additionally calls `$container->set(ConfigInterface::class, $configRepo)` right after building the container, so `ConfigInterface` always resolves to the exact repository instance the application booted with. Type-hint these interfaces in your constructors instead of concrete classes to keep your code testable:

```php
public function __construct(
    private \System\Contracts\DatabaseInterface $db,
    private \System\Contracts\SessionInterface $session
) {}
```

## How Controllers Get Dependencies

During `Application::boot()`, a `System\Core\ControllerFactory` is created with the container and handed to the router. When a route dispatches to a controller, the factory reflects the controller's constructor and resolves each parameter:

1. `System\Http\Request` and `System\Http\Response` type-hints receive the **current request/response objects** — not container entries.
2. Any other class/interface type-hint is resolved from the container (`$container->get($name)`), which triggers definitions or autowiring.
3. If resolution fails, the parameter's **default value** is used if available.
4. If the parameter is **nullable**, `null` is passed.
5. Otherwise a `RuntimeException` names the unresolvable parameter.

```php
class OrderController extends BaseController
{
    public function __construct(
        Request $request,                       // current request (special-cased)
        private App\Services\OrderService $orders,  // from the container
        private ?App\Services\Audit $audit = null   // optional dependency
    ) {}
}
```

## Accessing the Container

The container is created privately inside `Application::__construct()` and passed to the router and controller factory — `Application` does not expose a public accessor for it. In practice you should not need to touch the container directly: register services in `config/dependencies.php` and receive them through constructor injection. If a service genuinely needs the container itself (e.g. a service locator for dynamically chosen strategies), bind it in your definitions file — factory closures already receive it:

```php
// config/dependencies.php
return [
    \System\Contracts\ContainerInterface::class =>
        fn (\System\Contracts\ContainerInterface $c) => $c,
];
```

Then type-hint `ContainerInterface` where needed. Use this sparingly — explicit dependencies are easier to test and reason about.

## Explicit Registration vs Autowiring

**Rely on autowiring** when a class's constructor only type-hints concrete classes (or already-bound interfaces) and builtin parameters all have defaults. Zero configuration needed — `get(MyService::class)` just works.

**Register an explicit definition** when the service:

- implements an **interface** you want to type-hint against (autowiring can't pick an implementation),
- needs **scalar configuration** — connection strings, API keys, file paths, TTLs (the container refuses to guess builtin values with no default),
- requires **setup logic** — reading config, validating keys (see the framework's `JwtAuthMiddleware` definition), conditional construction,
- should be replaced per-application — defining the same id in your app's `config/dependencies.php` **overrides the framework default**.

::: warning Gotchas
- **Everything is a singleton.** Both definition results and autowired classes are cached in the instance map; `get()` never builds twice. There is no transient/factory-per-call scope — if you need fresh instances, resolve a factory closure from the container and call it yourself.
- `has($id)` returns `true` for *any* existing class, even one whose constructor the container could not actually satisfy — a subsequent `get()` may still throw.
- Autowiring only understands single named type-hints. Union types, intersection types, and untyped parameters without defaults cause a `RuntimeException`.
- The interface bindings in `config/dependencies.php` are **fallbacks** that resolve through global singletons (`Database::getInstance()` etc.). `ConfigInterface` is re-bound by `Application` to the booted instance; the others resolve lazily on first `get()`.
- Definitions files are merged with `array_merge` — the *last* file loaded (your application's) silently wins on duplicate ids. That's the intended override mechanism, but watch for accidental collisions on generic string ids.
:::

## Related

- [Application Lifecycle](/guide/lifecycle)
- [Configuration](/guide/configuration)
- [Controllers](/essentials/controllers)
- [Middleware](/essentials/middleware)
- [Helpers](/features/helpers)
