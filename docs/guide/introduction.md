# Introduction

Yantra is a modern, lightweight PHP framework built for performance, security, and developer productivity. It provides an elegant MVC architecture with a powerful Active Record ORM, WordPress-like hooks, comprehensive validation, and built-in security middleware — all without pulling in a tree of third-party packages.

```php
// public/index.php — a complete Yantra application entry point
use System\Core\Application;

$app = Application::create(env('APP_ENV', 'production'));

$app->initRoutes([
    'web' => APPPATH . '/Routes/web.php',
    'api' => APPPATH . '/Routes/api.php',
]);

$app->boot()->run();
```

**Version:** 0.1.1 · **PHP:** >= 8.0 · **License:** MIT

## The Zero-Dependency Philosophy

Yantra has **zero production dependencies**. The entire framework runs on:

- PHP >= 8.0
- `ext-pdo` — database access
- `ext-fileinfo` — file type detection
- `ext-iconv` — character encoding

That's it. No external runtime libraries, no transitive dependency tree to audit, no version conflicts with your application's own packages. Everything the framework offers — routing, ORM, validation, JWT, mail transports, queues, the DI container — is implemented natively in `src/System`.

This buys you:

- **Auditability** — every line of code that runs in production is in one repository.
- **Stability** — no upstream package can break your build or ship a supply-chain surprise.
- **Fast installs** — `composer require yantra/framework` pulls a single package.

## Feature Tour

- **High-Performance Router** — static routes via O(1) hash lookup, dynamic routes via compiled regex, per-method caching. See [Routing](/essentials/routing).
- **Active Record ORM** — Eloquent-style models with relationships, scopes, accessors/mutators, and casting. See [Models](/database/models) and [Relationships](/database/relationships).
- **Fluent Query Builder** — joins, CTEs, subqueries, aggregates, and pagination. See [Query Builder](/database/query-builder).
- **PHP Templating** — native PHP templates with layouts, sections, partials, and view namespaces. See [Views](/essentials/views).
- **WordPress-like Hooks** — actions and filters for a pluggable architecture. See [Hooks](/features/hooks).
- **60+ Validation Rules** — including database-aware rules (`unique`, `exists`), file validation, and India-specific ID rules. See [Validation](/essentials/validation).
- **Security First** — CSRF protection, JWT auth, security headers, rate limiting, cookie hardening, and audit logging. See [Security Overview](/security/overview).
- **Built-in CLI Tool** — 23+ artisan-style commands for scaffolding, migrations, caching, and more. See [CLI](/features/cli).
- **Queue System** — async job processing with database, file, and Redis adapters. See [Queues](/features/queues).
- **Mail Service** — SMTP, SendGrid, and Mailgun transports with queue integration. See [Mail](/features/mail).
- **Task Scheduler** — cron-like scheduling with expression parsing. See [Scheduler](/features/scheduler).
- **Testing Toolkit** — TestCase, HTTP test client, and isolated sandboxes for DB, session, cache, and filesystem. See [Testing](/testing/getting-started).

## Design Patterns

| Pattern | Where Used |
|---------|------------|
| **Singleton** | Application, Database, Config, SessionStore |
| **Active Record** | Model (CRUD via instance methods) |
| **Builder** | QueryBuilder (fluent SQL construction) |
| **Strategy** | Session adapters, Cache adapters, Queue adapters |
| **Factory** | ControllerFactory (controller resolution) |
| **Pipeline** | Middleware stack (Kernel, Router) |
| **Observer** | Hooks (WordPress-like action/filter system) |
| **Repository** | MigrationRepository |
| **Facade** | Config, Cache, View, Hooks, SessionStore |
| **Value Object** | RouteDefinition, GroupDefinition, MigrationResult |

## Key Architectural Decisions

1. **Zero production dependencies** — the framework avoids heavy dependency trees entirely.
2. **Static facades with instances** — public APIs use static facades (`Config::get()`, `Cache::put()`) backed by swappable instance-based implementations.
3. **Scope-aware routing** — separate route compilation and middleware stacks for `web`, `api`, and `admin` scopes.
4. **Performance-first routing** — static routes use O(1) hash lookup; dynamic routes use compiled regex. Routes are cached per HTTP method.
5. **Security by default** — the global middleware pipeline includes request normalization, security headers, cookie hardening, CSRF protection, and audit logging.
6. **Database agnostic** — supports MySQL, MariaDB, PostgreSQL, and SQLite through PDO abstraction.

## Contributing

Contributions are welcome:

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Commit your changes: `git commit -m 'Add my feature'`
4. Push to the branch: `git push origin feature/my-feature`
5. Open a Pull Request

Before submitting, run the test suite:

```bash
composer test
```

## Related

- [Installation](/guide/installation)
- [Directory Structure](/guide/directory-structure)
- [Request Lifecycle](/guide/lifecycle)
- [Tutorial: Setup](/tutorial/01-setup)
