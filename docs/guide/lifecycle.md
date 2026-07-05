# Request Lifecycle

Every HTTP request to a Yantra application flows through a single, predictable pipeline: the `public/index.php` entry point creates the `Application`, registers route files, boots the framework, and hands the request to the `Kernel`, which runs middleware and dispatches to your controller. Understanding this flow makes everything else — routing, middleware, dependency injection — click into place.

```php
// public/index.php
$app = Application::create(env('APP_ENV', 'production'));

$app->initRoutes([
    'web' => APPPATH . '/Routes/web.php',
    'api' => APPPATH . '/Routes/api.php',
]);

$app->boot()->run();
```

## The Flow at a Glance

```
1. public/index.php
   │
2. Application::create($environment)
   ├── Load .env file
   ├── Initialize DI container (config/dependencies.php)
   └── Store singleton instance
   │
3. Application::initRoutes($routeFiles)
   ├── Create Router per scope (web, api, admin)
   ├── Load route definitions via RouteCollector
   └── Compile & cache routes (static/dynamic separation)
   │
4. Application::run()
   ├── Create Request from PHP globals
   ├── Create Response object
   ├── Detect scope from URL (/api/* → api, /admin/* → admin, else web)
   └── Pass to Kernel
   │
5. Kernel::handle($request, $response)
   ├── Execute global middleware pipeline
   └── Router::dispatch()
       ├── Static route lookup (O(1) hash)
       ├── Dynamic route matching (compiled regex)
       ├── Resolve route middleware
       └── Instantiate controller via ControllerFactory
   │
6. Controller action executes
   ├── Business logic, model queries, validation
   └── Return Response (view, JSON, redirect)
   │
7. Response emitted to client
```

## Stage by Stage

### 1. Entry Point

The web server routes every request to `public/index.php`, which defines `BASEPATH` and `APPPATH`, loads the Composer autoloader, and builds the application.

### 2. `Application::create()`

The constructor does the heavy lifting:

- **Loads `.env`** from the project root, exporting each key via `putenv()`, `$_ENV`, and `$_SERVER`. A present-but-unparseable `.env` throws immediately — the framework refuses to boot against default config (see [Configuration](/guide/configuration)).
- **Wires configuration** — a `ConfigRepository` pointed at `App/Config` is created and installed behind the static `Config` facade.
- **Applies the timezone** from `app.timezone` (falling back to UTC) so web and CLI code agree on time.
- **Initializes the DI container**, loading framework defaults from the framework's `config/dependencies.php`, then your project's `config/dependencies.php` on top, and binding `ConfigInterface` to the shared config instance.

### 3. `Application::initRoutes()`

You pass a map of scope → route file(s) (`web`, `api`, `admin`). Each scope gets its own compiled route cache under `storage/cache/routes/{scope}/`. In the `development` environment, all scopes are eagerly recompiled on every request for fast iteration; otherwise compilation happens only when a scope's cache is missing.

### 4. `Application::boot()`

Booting prepares all subsystems:

- Starts the **session** (a cookie-hardened `NativeSessionAdapter` by default — HttpOnly, SameSite=Lax, Secure when on HTTPS) unless you already registered an adapter via `addSessionAdapter()`.
- **Detects the route scope** from the request path (`/admin/*` → `admin`, `/api/*` → `api`, everything else → `web`) and creates the `Router` against that scope's cache directory only.
- Resolves the **ViewRenderer** from the container and connects it to the router's **ControllerFactory**, so controllers get constructor dependencies auto-injected.
- **Connects the database** — only if a `db` config exists. No config, no connection; Yantra never forces one.
- Builds the **Kernel** with the router, environment, and logger, then wires the **middleware resolver**: groups and aliases come from the root `config/middleware.php`, and the global middleware list from the `middleware.global` config key. See [Middleware](/essentials/middleware).

### 5–7. `run()`: Kernel, Controller, Response

`run()` constructs a `Request` from PHP globals and a fresh `Response`, then calls `Kernel::handle()`. The kernel executes the global middleware pipeline and asks the router to dispatch: static routes are found via O(1) hash lookup, dynamic routes via compiled regex; route-level middleware is resolved; and the controller is instantiated through the `ControllerFactory` with DI. Your action runs and returns a `Response` (view, JSON, or redirect — see [Responses](/essentials/responses)), which is finally sent to the client with `$response->emit()`.

::: warning Gotchas
- Calling `run()` without `boot()` is safe — `run()` boots automatically if the kernel isn't ready. Calling `boot()` explicitly (as the scaffold does) just makes the sequence obvious.
- Only the **matched scope's** route cache is loaded per request. If a route seems missing in production, check that its scope was compiled (`php yantra routes:cache`) and that the path prefix maps to the scope you expect.
- Errors surfacing during dispatch are converted to HTTP responses by the framework's error handling — see [Error Handling](/essentials/error-handling).
:::

## The CLI Boot Path

Console commands (`php vendor/bin/yantra ...`) boot the same application through `bin/yantra`, with a few differences:

1. **Project root discovery** — the script walks upward from the current working directory until it finds `vendor/autoload.php`, then defines `BASEPATH` and `APPPATH` from that root. You can run `yantra` from any subdirectory of your project.
2. **Command registration** — a `CommandRegistry` is created and populated: the built-in `list` and `help` commands first, then all framework commands and your app's commands in `App/Cli/Commands/` via auto-discovery.
3. **Application boot** — a full `Application` is constructed (environment `production`) and `boot()` is called, so commands have access to config, the database, sessions, and the container exactly as web code does. The registry is attached to the application via `setCommandRegistry()`.
4. **Dispatch** — a `ConsoleApplication` wraps the registry and runs the requested command from `$argv`, and its integer return value becomes the process exit code.

See [CLI](/features/cli) for writing your own commands.

## Related

- [Installation](/guide/installation)
- [Configuration](/guide/configuration)
- [Routing](/essentials/routing)
- [Middleware](/essentials/middleware)
- [Controllers](/essentials/controllers)
