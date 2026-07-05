# Directory Structure

A Yantra project separates *your* code (`App/`, `database/`, `public/`) from the framework itself (installed under `vendor/`, sourced from `src/System`). Running `php vendor/bin/yantra app:scaffold` creates the application layout below; nothing about it is mandatory beyond `public/index.php` and the paths you pass to `initRoutes()`.

## Application Structure

```
project-root/
├── App/
│   ├── Config/              # Application configuration files
│   │   ├── app.php
│   │   ├── db.php
│   │   ├── cache.php
│   │   ├── session.php
│   │   ├── security.php
│   │   ├── mail.php
│   │   └── middleware.php
│   ├── Controllers/         # HTTP controllers
│   ├── Models/              # Eloquent-style models
│   ├── Views/               # PHP templates
│   ├── Routes/              # Route definitions
│   │   ├── web.php
│   │   ├── api.php
│   │   └── admin.php
│   ├── Middleware/          # Custom middleware
│   ├── Services/            # Business logic services
│   ├── Repositories/        # Data access repositories
│   └── Cli/
│       └── Commands/        # Custom CLI commands
├── database/
│   ├── migrations/          # Database migration files
│   └── seeders/             # Database seeders
├── public/
│   ├── index.php            # Web entry point
│   └── assets/              # CSS, JS, images
├── storage/
│   ├── cache/               # Route cache, view cache
│   ├── logs/                # Application logs
│   └── sessions/            # File-based sessions
├── themes/                  # Theme directories
├── .env                     # Environment configuration
├── composer.json
└── phpunit.xml
```

### Directory Notes

| Directory | Purpose |
|-----------|---------|
| `App/Config/` | PHP files returning config arrays, loaded lazily by key — see [Configuration](/guide/configuration) |
| `App/Controllers/` | Controllers resolved through the DI container — see [Controllers](/essentials/controllers) |
| `App/Models/` | Active Record models extending `System\Database\Model` — see [Models](/database/models) |
| `App/Views/` | Native PHP templates with layouts and partials — see [Views](/essentials/views) |
| `App/Routes/` | One file per route scope (`web`, `api`, `admin`) — see [Routing](/essentials/routing) |
| `App/Middleware/` | Custom middleware classes — see [Middleware](/essentials/middleware) |
| `App/Cli/Commands/` | Custom CLI commands, auto-discovered by the `yantra` console — see [CLI](/features/cli) |
| `database/migrations/` | Schema migrations — see [Migrations](/database/migrations) |
| `database/seeders/` | Seed data classes — see [Seeders](/database/seeders) |
| `public/` | The only web-accessible directory; the document root |
| `storage/cache/routes/` | Compiled route caches, one subdirectory per scope |
| `storage/logs/` | Application logs (default: `app.log`) |
| `themes/` | Installable themes — see [Themes](/features/themes) |

## Framework Source Structure

Inside the framework package, everything lives under `src/System/`:

```
src/System/
├── Core/                    # Application kernel, controller factory, error handling
│   └── Routing/             # Route collector, compiler, router, URL generator
├── Http/                    # PSR-7 Request/Response wrappers, cookies
├── Database/                # PDO wrapper, QueryBuilder, ORM Model, Schema
│   ├── Migrations/          # Migrator, repository, lock
│   ├── Schema/              # Blueprint, Schema builder
│   ├── Seeders/             # SeederRunner
│   └── Exceptions/          # DatabaseException, QueryException
├── Security/                # CSRF, Crypto, Password, JWT
│   └── Middleware/          # 9+ security middleware classes
├── Validation/              # Validator, 60+ rules, ErrorBag
├── Session/                 # SessionStore, Native/Redis adapters
├── View/                    # ViewRenderer
├── Theme/                   # ThemeManager, AssetManager
├── Services/                # Email, Queue, Scheduler, Webhooks, Reporting, Imports
├── Cli/                     # ConsoleApplication, CommandRegistry, 23+ commands
├── Testing/                 # TestCase, TestClient, TestResponse, Sandboxes
├── Helpers/                 # 14 utility classes (Array, String, Url, Form, etc.)
├── Utilities/               # Cache (File, Redis), RequestCache
├── Support/                 # Collection class
├── Contracts/               # Framework interfaces
├── Config.php               # Static configuration facade
├── Controller.php           # Base controller
├── Hooks.php                # Action/filter event system
└── functions.php            # Global helper functions
```

### Where to Look

- **`Core/`** — the `Application` bootstrap, `Kernel` (middleware pipeline + dispatch), `Container` (DI), and the routing subsystem. See [Request Lifecycle](/guide/lifecycle) and [Container](/features/container).
- **`Http/`** — `Request` and `Response` objects passed to every controller. See [Requests](/essentials/requests) and [Responses](/essentials/responses).
- **`Database/`** — the full data layer: connection management, [Query Builder](/database/query-builder), the [Model](/api/model) base class, schema builder, and migration runner.
- **`Security/`** — [CSRF](/security/csrf), [JWT](/security/jwt), [Crypto](/security/crypto), and the security middleware stack.
- **`Contracts/`** — interfaces the framework codes against (`ConfigInterface`, `LoggerInterface`, session/cache adapters), which you can rebind in the DI container.
- **`functions.php`** — global helpers like `env()`, `config()`, `route()`, `session()`. See [Helpers](/features/helpers).

::: warning Gotchas
Two config locations exist and serve different purposes: `App/Config/` holds application configuration arrays (loaded via `Config::get()`), while a root-level `config/` directory holds framework wiring — `config/dependencies.php` (DI definitions) and `config/middleware.php` (middleware groups and aliases).
:::

## Related

- [Installation](/guide/installation)
- [Configuration](/guide/configuration)
- [Request Lifecycle](/guide/lifecycle)
- [Routing](/essentials/routing)
