# Yantra Framework

A modern, lightweight PHP framework built for performance, security, and developer productivity. Yantra provides an elegant MVC architecture with a powerful ORM, WordPress-like hooks, comprehensive validation, and built-in security middleware — all with minimal external dependencies.

**Version:** 0.1.0
**PHP:** >= 8.0
**License:** MIT

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Project Structure](#project-structure)
- [Configuration](#configuration)
- [Request Lifecycle](#request-lifecycle)
- [Routing](#routing)
- [Controllers](#controllers)
- [Models & ORM](#models--orm)
- [Query Builder](#query-builder)
- [Database Migrations](#database-migrations)
- [Schema Builder](#schema-builder)
- [Database Seeders](#database-seeders)
- [Views & Templating](#views--templating)
- [Middleware](#middleware)
- [Validation](#validation)
- [Session Management](#session-management)
- [Caching](#caching)
- [Hooks (Event System)](#hooks-event-system)
- [Authentication & Security](#authentication--security)
- [Mail Service](#mail-service)
- [Queue System](#queue-system)
- [Task Scheduler](#task-scheduler)
- [Webhooks](#webhooks)
- [CLI Commands (Yantra Console)](#cli-commands-yantra-console)
- [Helper Functions](#helper-functions)
- [Testing](#testing)
- [Logging](#logging)
- [Error Handling](#error-handling)
- [Theme Management](#theme-management)
- [Reporting & CSV Imports](#reporting--csv-imports)
- [Dependency Injection Container](#dependency-injection-container)
- [Architecture & Design Patterns](#architecture--design-patterns)

---

## Features

- **MVC Architecture** — Clean separation of concerns with controllers, models, and views
- **High-Performance Router** — Static routes via O(1) hash lookup, dynamic routes via compiled regex, per-method caching
- **Active Record ORM** — Eloquent-style models with relationships, scopes, accessors/mutators, and casting
- **Fluent Query Builder** — Comprehensive SQL builder with joins, CTEs, subqueries, aggregates, and pagination
- **PHP Templating** — Native PHP templates with layouts, sections, partials, and view namespaces
- **WordPress-like Hooks** — Actions and filters for extensible, pluggable architecture
- **60+ Validation Rules** — Including database-aware rules (`unique`, `exists`), file validation, and India-specific ID rules
- **Security First** — CSRF protection, JWT auth, security headers, rate limiting, cookie hardening, and audit logging
- **Built-in CLI Tool** — 23+ artisan-style commands for scaffolding, migrations, caching, and more
- **Queue System** — Async job processing with database, file, and Redis adapters
- **Mail Service** — SMTP, SendGrid, and Mailgun transports with queue integration
- **Task Scheduler** — Cron-like scheduling with expression parsing
- **Zero Production Dependencies** — Only requires PHP 8.0+ and PDO, no external runtime libraries

---

## Requirements

- PHP >= 8.0
- PDO extension (`ext-pdo`)
- Fileinfo extension (`ext-fileinfo`)
- Iconv extension (`ext-iconv`)
- A supported database: MySQL, MariaDB, PostgreSQL, or SQLite

---

## Installation

```bash
composer require yantra/framework
```

### Application Scaffolding

After installing the framework, scaffold a new application:

```bash
php vendor/bin/yantra app:scaffold
```

This creates the standard application directory structure under `App/`.

### Manual Setup

1. Create your entry point `public/index.php`:

```php
<?php
declare(strict_types=1);

define('BASEPATH', dirname(__DIR__));
define('APPPATH', BASEPATH . '/App');

require BASEPATH . '/vendor/autoload.php';

use System\Core\Application;

$app = Application::create(env('APP_ENV', 'production'));

$app->initRoutes([
    'web' => APPPATH . '/Routes/web.php',
    'api' => APPPATH . '/Routes/api.php',
]);

$app->boot()->run();
```

2. Create a `.env` file in your project root:

```ini
APP_NAME="My Yantra App"
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost

DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=my_database
DB_USERNAME=root
DB_PASSWORD=secret
DB_CHARSET=utf8mb4

CACHE_DRIVER=file
SESSION_DRIVER=native

MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
```

---

## Project Structure

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
│   ├── Middleware/           # Custom middleware
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

### Framework Source Structure

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
│   └── Middleware/           # 9+ security middleware classes
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

---

## Configuration

### Environment Variables

Yantra loads `.env` files from your project root. Access environment values with the `env()` helper:

```php
$debug = env('APP_DEBUG', false);
$dbHost = env('DB_HOST', 'localhost');
```

Boolean-like strings are automatically cast: `"true"` becomes `true`, `"false"` becomes `false`, `"null"` becomes `null`.

### Config Files

Configuration files live in `App/Config/` and return PHP arrays. Access values using dot-notation:

```php
// App/Config/app.php
return [
    'name'        => env('APP_NAME', 'Yantra'),
    'environment' => env('APP_ENV', 'production'),
    'debug'       => env('APP_DEBUG', false),
    'url'         => env('APP_URL', 'http://localhost'),
    'timezone'    => 'UTC',
    'locale'      => 'en',
];
```

```php
// Read config values anywhere in your application
use System\Config;

$appName = Config::get('app.name');
$debug   = Config::get('app.debug', false);

// Or use the helper function
$appName = config('app.name');
$debug   = config('app.debug', false);

// Set config values at runtime
Config::set('app.timezone', 'Asia/Kolkata');
```

### Database Configuration

```php
// App/Config/db.php
return [
    'driver'   => env('DB_DRIVER', 'mysql'),
    'host'     => env('DB_HOST', 'localhost'),
    'port'     => env('DB_PORT', 3306),
    'database' => env('DB_DATABASE', 'yantra'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset'  => env('DB_CHARSET', 'utf8mb4'),
];
```

---

## Request Lifecycle

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

---

## Routing

### Defining Routes

Routes are defined in route files using a `RouteCollector` instance:

```php
// App/Routes/web.php
use System\Core\Routing\RouteCollector;

return function (RouteCollector $r) {
    // Basic routes
    $r->get('/', 'HomeController@index');
    $r->get('/about', 'HomeController@about');

    // Route with parameters
    $r->get('/users/{id}', 'UserController@show');
    $r->get('/posts/{slug}', 'PostController@show');

    // HTTP method-specific routes
    $r->post('/users', 'UserController@store');
    $r->put('/users/{id}', 'UserController@update');
    $r->patch('/users/{id}', 'UserController@update');
    $r->delete('/users/{id}', 'UserController@destroy');

    // Match multiple methods
    $r->match(['GET', 'POST'], '/contact', 'ContactController@handle');

    // Any HTTP method
    $r->any('/webhook', 'WebhookController@handle');
};
```

### Named Routes

```php
$r->get('/users/{id}', 'UserController@show')->name('users.show');
$r->get('/dashboard', 'DashboardController@index')->name('dashboard');

// Generate URLs from route names
$url = route('users.show', ['id' => 5]);          // → /users/5
$url = route('dashboard');                          // → /dashboard
$url = route('users.show', ['id' => 5], ['tab' => 'posts']);  // → /users/5?tab=posts
```

### Route Groups

```php
// Group with prefix
$r->group('/admin', [], function (RouteCollector $r) {
    $r->get('/dashboard', 'Admin\DashboardController@index');
    $r->get('/users', 'Admin\UserController@index');
});

// Group with middleware
$r->group('/api', ['middleware' => ['auth.jwt', 'rate.limit']], function (RouteCollector $r) {
    $r->get('/profile', 'Api\ProfileController@show');
    $r->put('/profile', 'Api\ProfileController@update');
});

// Nested groups
$r->group('/admin', ['middleware' => ['auth']], function (RouteCollector $r) {
    $r->get('/dashboard', 'Admin\DashboardController@index');

    $r->group('/settings', [], function (RouteCollector $r) {
        $r->get('/', 'Admin\SettingsController@index');
        $r->post('/', 'Admin\SettingsController@update');
    });
});
```

### Route Middleware

```php
// Single middleware
$r->get('/profile', 'ProfileController@show')->middleware('auth');

// Multiple middleware
$r->post('/admin/users', 'Admin\UserController@store')
  ->middleware(['auth', 'role:admin']);

// Middleware with parameters
$r->post('/api/data', 'ApiController@store')
  ->middleware(['rate.limit:60,1']);  // 60 requests per 1 minute
```

### Route Model Binding

Automatically resolve model instances from route parameters:

```php
// Register model binding on the router
$router->model('user', App\Models\User::class);           // Bind by primary key
$router->model('post', App\Models\Post::class, 'slug');   // Bind by column

// Route definition
$r->get('/users/{user}', 'UserController@show');
$r->get('/posts/{post}', 'PostController@show');

// Controller receives resolved model instance
public function show(User $user): Response
{
    // $user is automatically fetched from the database
    return $this->render('users.show', ['user' => $user]);
}
```

### Error Routes

```php
$r->error(404, function ($req, $res) {
    return $res->html('<h1>Page Not Found</h1>', 404);
});

$r->error(405, function ($req, $res) {
    return $res->html('<h1>Method Not Allowed</h1>', 405);
});
```

### API Routes

```php
// App/Routes/api.php
return function (RouteCollector $r) {
    $r->get('/users', 'Api\UserController@index');
    $r->post('/users', 'Api\UserController@store');
    $r->get('/users/{id}', 'Api\UserController@show');
    $r->put('/users/{id}', 'Api\UserController@update');
    $r->delete('/users/{id}', 'Api\UserController@destroy');
};
// All API routes are automatically prefixed with /api
```

### Route Caching

Routes are compiled and cached for high performance:

```bash
# Cache all routes
php yantra routes:cache

# Clear route cache
php yantra routes:clear

# List all registered routes
php yantra routes:list
```

Cache structure:
```
storage/cache/routes/{scope}/
├── GET.php          # Compiled GET routes
├── POST.php         # Compiled POST routes
├── PUT.php          # Compiled PUT routes
├── DELETE.php       # Compiled DELETE routes
├── PATCH.php        # Compiled PATCH routes
├── __index.php      # SHA1-indexed static route lookup
└── __errors.php     # Error handler definitions
```

---

## Controllers

### Creating Controllers

```bash
php yantra make:controller UserController
```

### Base Controller

All controllers extend `BaseController`, which provides response helpers:

```php
<?php
namespace App\Controllers;

use System\Core\BaseController;
use System\Http\Request;
use System\Http\Response;

class UserController extends BaseController
{
    // Render a view
    public function index(): Response
    {
        $users = User::all();
        return $this->render('users.index', ['users' => $users]);
    }

    // Render with a layout
    public function show(int $id): Response
    {
        $user = User::findOrFail($id);
        return $this->render('users.show', ['user' => $user], 'layouts.main');
    }

    // JSON success response
    public function apiIndex(): Response
    {
        $users = User::all();
        return $this->success($users);
        // Returns: {"status":"success","data":[...]}
    }

    // JSON error response
    public function apiError(): Response
    {
        return $this->error('User not found', 404);
        // Returns: {"status":"error","message":"User not found"}
    }

    // Redirect
    public function store(): Response
    {
        // ... save user
        return $this->redirect('/users');
    }

    // Post-Redirect-Get pattern
    public function update(int $id): Response
    {
        // ... update user
        return $this->redirectAfterPost('/users/' . $id);
    }

    // Hybrid response (JSON for AJAX, redirect for forms)
    public function create(): Response
    {
        // ... create user
        return $this->respond('/users', $newUser);
    }

    // Validation error response
    public function validateAndStore(): Response
    {
        $validator = new Validator($this->req()->all(), [
            'name' => 'required|string|max:100',
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->all());
        }

        // ... save
    }
}
```

### Accessing Request Data

```php
class UserController extends BaseController
{
    public function store(): Response
    {
        // Access the request object
        $request = $this->req();

        // Get input values
        $name = $request->input('name');
        $email = $request->input('email', 'default@example.com');
        $all = $request->all();

        // Check HTTP method
        if ($this->isPost()) {
            // Handle POST
        }

        if ($this->methodIs('PUT')) {
            // Handle PUT
        }

        // CSRF validation
        if (!$this->validateCsrf('form_scope')) {
            return $this->error('Invalid CSRF token', 403);
        }

        return $this->success(['saved' => true]);
    }
}
```

### Controller Dependency Injection

Controllers are resolved through the DI container. Constructor dependencies are auto-injected:

```php
class UserController extends BaseController
{
    public function __construct(
        Request $request,
        Response $response,
        private UserService $userService,
        private MailService $mailService
    ) {
        parent::__construct($request, $response);
    }

    public function store(): Response
    {
        $user = $this->userService->create($this->req()->all());
        $this->mailService->sendWelcome($user);
        return $this->success($user, 201);
    }
}
```

---

## Models & ORM

### Creating Models

```bash
php yantra make:model User
php yantra db:make-model users   # Generate model from existing table
```

### Model Definition

```php
<?php
namespace App\Models;

use System\Database\Model;

class User extends Model
{
    // Table name (auto-inferred as "users" from class name if omitted)
    protected ?string $tableName = 'users';

    // Mass-assignable fields
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

### CRUD Operations

```php
// CREATE
$user = new User();
$user->name = 'John Doe';
$user->email = 'john@example.com';
$user->password = password_hash('secret', PASSWORD_DEFAULT);
$user->save();

// Or use mass assignment
$user = User::create([
    'name'     => 'John Doe',
    'email'    => 'john@example.com',
    'password' => password_hash('secret', PASSWORD_DEFAULT),
]);

// READ
$user = User::find(1);                     // Find by ID
$user = User::findOrFail(1);               // Find or throw exception
$users = User::all();                       // Get all records
$user = User::where('email', 'john@example.com')->first();

// UPDATE
$user = User::find(1);
$user->name = 'Jane Doe';
$user->save();

// Or update via query
User::where('role', 'guest')->update(['is_active' => false]);

// DELETE
$user = User::find(1);
$user->delete();

// Or delete via query
User::where('is_active', false)->delete();
```

### Relationships

```php
class User extends Model
{
    // One-to-One
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    // One-to-Many
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    // Belongs-To (inverse of hasOne/hasMany)
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // Many-to-Many
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }
}

// Usage
$user = User::find(1);
$profile = $user->profile;          // Lazy load one-to-one
$posts = $user->posts;              // Lazy load one-to-many
$company = $user->company;          // Lazy load belongs-to

// Query through relationships
$publishedPosts = $user->posts()->where('published', true)->get();
```

### Eager Loading

```php
// Eager load relationships (prevents N+1 queries)
$users = User::query()->withRelations(['posts', 'profile'])->get();

// Declarative eager loading via relations() method
class User extends Model
{
    protected function relations(): array
    {
        return ['posts', 'profile'];
    }
}
```

### Accessors & Mutators

```php
class User extends Model
{
    // Accessor: called when reading $user->name
    public function getNameAttribute(): string
    {
        return ucfirst($this->attributes['name']);
    }

    // Mutator: called when setting $user->password
    public function setPasswordAttribute(string $value): string
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }
}

$user->password = 'plain_text';   // Automatically hashed
echo $user->name;                  // Automatically ucfirst'd
```

### Query Scopes

```php
class User extends Model
{
    // Local scopes
    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    public function scopeRole($query, string $role): void
    {
        $query->where('role', $role);
    }

    public function scopeRecent($query, int $days = 7): void
    {
        $query->where('created_at', '>=', date('Y-m-d', strtotime("-{$days} days")));
    }
}

// Chain scopes fluently
$admins = User::active()->role('admin')->recent(30)->get();
```

### Model Events

```php
class User extends Model
{
    protected function creating(): void
    {
        $this->uuid = Crypto::randomHex(16);
    }

    protected function created(): void
    {
        // Send welcome email
    }

    protected function updating(): void
    {
        $this->updated_by = auth_user()['id'] ?? null;
    }

    protected function deleting(): void
    {
        // Cleanup related records
    }
}
```

### Serialization

```php
$user = User::find(1);

$array = $user->toArray();        // Convert to array
$json  = json_encode($user);      // JsonSerializable
```

---

## Query Builder

The query builder provides a fluent interface for constructing SQL queries:

```php
use System\Database\QueryBuilder;

$qb = new QueryBuilder();
```

### SELECT Queries

```php
// Basic select
$users = $qb->table('users')->select('id', 'name', 'email')->get();

// With conditions
$users = $qb->table('users')
    ->where('is_active', true)
    ->where('age', '>=', 18)
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->get();

// First result
$user = $qb->table('users')->where('email', 'john@example.com')->first();

// Distinct
$roles = $qb->table('users')->distinct()->select('role')->get();

// Raw select expressions
$stats = $qb->table('orders')
    ->selectRaw('COUNT(*) as total, SUM(amount) as revenue')
    ->where('status', 'completed')
    ->first();
```

### WHERE Clauses

```php
// Basic where
->where('status', 'active')
->where('age', '>', 18)
->orWhere('role', 'admin')

// Where IN
->whereIn('status', ['active', 'pending'])
->whereNotIn('role', ['banned', 'suspended'])

// Where NULL
->whereNull('deleted_at')
->whereNotNull('email_verified_at')

// Where Between
->whereBetween('created_at', ['2025-01-01', '2025-12-31'])

// Where Raw (for complex conditions)
->whereRaw('YEAR(created_at) = ?', [2025])
```

### JOINs

```php
$results = $qb->table('users')
    ->join('posts', 'users.id', '=', 'posts.user_id')
    ->leftJoin('profiles', 'users.id', '=', 'profiles.user_id')
    ->rightJoin('departments', 'users.dept_id', '=', 'departments.id')
    ->select('users.name', 'posts.title', 'profiles.bio')
    ->get();
```

### Aggregates

```php
$count   = $qb->table('users')->count();
$total   = $qb->table('orders')->where('status', 'paid')->sum('amount');
$average = $qb->table('products')->avg('price');
$max     = $qb->table('orders')->max('amount');
$min     = $qb->table('products')->min('price');
```

### GROUP BY & HAVING

```php
$stats = $qb->table('orders')
    ->select('user_id')
    ->selectRaw('COUNT(*) as order_count, SUM(amount) as total_spent')
    ->groupBy('user_id')
    ->having('order_count', '>', 5)
    ->get();
```

### INSERT

```php
// Single insert
$id = $qb->table('users')->insert([
    'name'  => 'John',
    'email' => 'john@example.com',
]);

// Batch insert
$qb->table('users')->batchInsert([
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob',   'email' => 'bob@example.com'],
]);

// Insert ignore (skip duplicates)
$qb->table('users')->insertIgnore([
    'email' => 'john@example.com',
    'name'  => 'John',
]);

// Upsert (insert or update on duplicate key)
$qb->table('users')->onDuplicateKey(
    ['email' => 'john@example.com', 'name' => 'John'],
    ['name']  // Columns to update on conflict
);
```

### UPDATE & DELETE

```php
// Update
$affected = $qb->table('users')
    ->where('id', 1)
    ->update(['name' => 'Jane Doe']);

// Increment / Decrement
$qb->table('posts')->where('id', 1)->increment('views');
$qb->table('products')->where('id', 1)->increment('stock', 10);

// Delete
$deleted = $qb->table('users')->where('is_active', false)->delete();
```

### Pagination

```php
// Paginated results
$paginator = $qb->table('users')
    ->where('is_active', true)
    ->orderBy('name')
    ->paginate(perPage: 15, page: 2);

$paginator->items();       // Current page items
$paginator->currentPage();  // 2
$paginator->lastPage();     // Total pages
$paginator->total();        // Total records
$paginator->hasMorePages(); // true/false
```

### Common Table Expressions (CTEs)

```php
$results = $qb->table('ranked_users')
    ->with('ranked_users', function ($sub) {
        $sub->table('users')
            ->selectRaw('*, ROW_NUMBER() OVER (ORDER BY score DESC) as rank');
    })
    ->where('rank', '<=', 10)
    ->get();
```

### Transactions

```php
use System\Database\Database;

Database::beginTransaction();
try {
    $qb->table('accounts')->where('id', 1)->update(['balance' => 900]);
    $qb->table('accounts')->where('id', 2)->update(['balance' => 1100]);
    Database::commit();
} catch (\Throwable $e) {
    Database::rollBack();
    throw $e;
}
```

---

## Database Migrations

### Creating Migrations

```bash
php yantra make:migration create_users_table
php yantra make:migration add_role_to_users
```

### Writing Migrations

```php
<?php
use System\Database\Migrations\MigrationInterface;
use System\Database\Schema\Schema;
use System\Database\Schema\Blueprint;

class CreateUsersTable implements MigrationInterface
{
    public function up(PDO $pdo): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 100);
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->string('role', 20)->default('user');
            $table->boolean('is_active')->default(true);
            $table->text('bio')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(PDO $pdo): void
    {
        Schema::dropIfExists('users');
    }
}
```

### Running Migrations

```bash
# Run all pending migrations
php yantra migrate

# Rollback the last batch
php yantra migrate:rollback

# Rollback all and re-run
php yantra migrate:refresh

# Check migration status
php yantra migrate:status
```

---

## Schema Builder

### Column Types

```php
Schema::create('products', function (Blueprint $table) {
    // Auto-increment primary key
    $table->increments('id');
    $table->bigInteger('big_id');

    // Numeric
    $table->integer('quantity');
    $table->tinyInteger('priority');
    $table->decimal('price', 10, 2);
    $table->float('rating');

    // String
    $table->string('name', 255);
    $table->text('description');
    $table->longText('content');

    // Date & Time
    $table->date('publish_date');
    $table->datetime('event_at');
    $table->timestamp('verified_at')->nullable();
    $table->timestamps();  // created_at & updated_at

    // Boolean
    $table->boolean('is_featured');

    // JSON
    $table->json('metadata');
});
```

### Column Modifiers

```php
$table->string('nickname')->nullable();
$table->string('role')->default('user');
$table->string('email')->unique();
```

### Indexes & Foreign Keys

```php
$table->primary('id');
$table->unique('email');
$table->index('status');

$table->foreign('user_id')
    ->references('id')
    ->on('users');
```

### Drop Tables

```php
Schema::drop('users');
Schema::dropIfExists('users');
```

---

## Database Seeders

### Creating Seeders

```bash
php yantra make:seeder UsersTableSeeder
```

### Writing Seeders

```php
<?php
use System\Database\Seeders\SeederInterface;

class UsersTableSeeder implements SeederInterface
{
    public function run(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)'
        );

        $stmt->execute(['Admin', 'admin@example.com', password_hash('admin', PASSWORD_DEFAULT), 'admin']);
        $stmt->execute(['User', 'user@example.com', password_hash('user', PASSWORD_DEFAULT), 'user']);
    }
}
```

### Database Seeder (Master)

```php
class DatabaseSeeder implements SeederInterface
{
    public function run(PDO $pdo): void
    {
        $this->call(UsersTableSeeder::class);
        $this->call(PostsTableSeeder::class);
        $this->call(CategoriesTableSeeder::class);
    }
}
```

### Running Seeders

```bash
php yantra db:seed
```

---

## Views & Templating

Yantra uses native PHP templates with a layout/section system, partials, and view namespaces.

### Rendering Views

```php
// In a controller
return $this->render('users.index', ['users' => $users]);

// With layout
return $this->render('users.show', ['user' => $user], 'layouts.main');
```

### PHP Templates

```php
<!-- views/users/index.php -->
<?php /** @var array $users */ ?>

<h1>Users</h1>
<?php foreach ($users as $user): ?>
    <div>
        <h2><?= e($user['name']) ?></h2>
        <p><?= e($user['email']) ?></p>
    </div>
<?php endforeach; ?>
```

### View Sections & Layouts (PHP)

```php
// In a view
<?php View::section('sidebar'); ?>
    <ul>
        <li><a href="/dashboard">Dashboard</a></li>
        <li><a href="/settings">Settings</a></li>
    </ul>
<?php View::endSection(); ?>

// In layout
<aside><?= View::yield('sidebar') ?></aside>
```

### Partials

```php
// Render a partial view
<?= View::partial('components.alert', ['type' => 'success', 'message' => 'Saved!']) ?>
```

### View Namespaces

```php
// Register a namespace
$viewRenderer->addNamespace('admin', '/path/to/admin/views');

// Use namespaced view
return $this->render('admin::dashboard', $data);
```

### Static View Facade

```php
use System\View\View;

$html = View::render('emails.welcome', ['name' => 'John']);
```

---

## Middleware

### Built-in Security Middleware

| Alias | Class | Purpose |
|-------|-------|---------|
| `sec.normalize` | RequestNormalizationMiddleware | Validate HTTP method and body size |
| `sec.headers` | SecurityHeadersMiddleware | X-Content-Type-Options, Referrer-Policy, etc. |
| `sec.cookies` | CookieHardeningMiddleware | HttpOnly, Secure, SameSite on all cookies |
| `sec.csrf` | CsrfMiddleware | CSRF token validation on POST/PUT/PATCH/DELETE |
| `sec.audit` | AuditMiddleware | Request audit logging |
| `sec.csp` | CspNonceMiddleware | Content Security Policy nonces |
| `auth` | AuthGuardMiddleware | Session-based authentication |
| `auth.jwt` | JwtAuthMiddleware | JWT bearer token authentication |
| `rate.limit` | RateLimitMiddleware | Request rate limiting |
| `guest` | GuestOnlyMiddleware | Redirect authenticated users |
| `cors` | CorsMiddleware | CORS header handling |

### Creating Custom Middleware

```bash
php yantra make:middleware LogRequestMiddleware
```

```php
<?php
namespace App\Middleware;

use System\Http\Request;
use System\Http\Response;

class LogRequestMiddleware
{
    public function __invoke(Request $req, Response $res, callable $next, array $params = []): Response
    {
        // Before: run logic before the controller
        $start = microtime(true);

        // Pass to next middleware / controller
        $response = $next($req, $res);

        // After: run logic after the controller
        $duration = round((microtime(true) - $start) * 1000, 2);
        error_log("Request: {$req->method()} {$req->path()} — {$duration}ms");

        return $response;
    }
}
```

### Registering Middleware

Middleware configuration is defined in `App/Config/middleware.php`:

```php
return [
    // Global middleware (runs on every request)
    'global' => [
        'sec.normalize',
        'sec.headers',
        'sec.cookies',
        'sec.csrf',
    ],

    // Middleware groups
    'groups' => [
        'web' => [
            'sec.csrf',
            App\Middleware\SessionMiddleware::class,
        ],
        'api' => [
            'auth.jwt',
            'rate.limit',
        ],
    ],

    // Middleware aliases
    'aliases' => [
        'auth'   => App\Middleware\AuthMiddleware::class,
        'admin'  => App\Middleware\AdminMiddleware::class,
        'log'    => App\Middleware\LogRequestMiddleware::class,
    ],
];
```

---

## Validation

### Basic Usage

```php
use System\Validation\Validator;

$validator = new Validator($request->all(), [
    'name'     => 'required|string|max:100',
    'email'    => 'required|email|unique:users',
    'password' => 'required|string|min:8|confirmed',
    'age'      => 'integer|gte:18|lte:120',
    'role'     => 'required|in:admin,editor,user',
]);

if ($validator->fails()) {
    $errors = $validator->errors();
    $firstError = $errors->first('email');
    $allErrors = $errors->all();
    return $this->validationError($allErrors);
}

// Validation passed — proceed
```

### Available Rules (60+)

**Presence & Type:**
```
required                     # Must be present and non-empty
required_if:field,value      # Required when another field equals value
required_with:field          # Required when another field is present
required_without:field       # Required when another field is absent
string                       # Must be a string
integer                      # Must be an integer
numeric                      # Must be numeric
boolean                      # Must be boolean
array                        # Must be an array
```

**Size & Length:**
```
min:3                        # Minimum length/value
max:255                      # Maximum length/value
between:1,100                # Between min and max
size:10                      # Exact size
```

**Comparison:**
```
same:field                   # Must match another field
different:field              # Must differ from another field
confirmed                    # Field must have matching {field}_confirmation
gt:field                     # Greater than another field
gte:18                       # Greater than or equal to value
lt:field                     # Less than another field
lte:100                      # Less than or equal to value
```

**Format:**
```
email                        # Valid email address
url                          # Valid URL
ip                           # Valid IP address
uuid                         # Valid UUID
json                         # Valid JSON string
regex:/^[A-Z]+$/             # Matches regex pattern
date                         # Valid date
before:2025-12-31            # Date before
after:2025-01-01             # Date after
```

**Database:**
```
unique:users,email           # Unique in table (column optional)
exists:users,id              # Exists in table
```

**Lists:**
```
in:admin,editor,user         # Must be in list
not_in:banned,suspended      # Must not be in list
distinct                     # Array values must be unique
```

**File:**
```
file                         # Must be an uploaded file
mimes:jpeg,png,pdf           # Allowed MIME types
max_file_size:2048           # Maximum file size in KB
```

**Special:**
```
password_strength            # Password complexity check
credit_card                  # Credit card number
mac                          # MAC address
hex_color                    # Hex color code (#fff or #ffffff)
aadhaar                      # Indian Aadhaar number
pan                          # Indian PAN number
gstin                        # Indian GSTIN
ifsc                         # Indian bank IFSC code
```

### Error Bag

```php
$errors = $validator->errors();

$errors->has('email');          // true/false
$errors->first('email');        // First error for field
$errors->get('email');          // All errors for field (array)
$errors->all();                 // All errors (flat array)
```

### Custom Validation Rules

```php
use System\Validation\Contracts\RuleInterface;

class StrongPasswordRule implements RuleInterface
{
    public function validate(string $field, mixed $value, array $data): bool
    {
        return preg_match('/[A-Z]/', $value)
            && preg_match('/[a-z]/', $value)
            && preg_match('/[0-9]/', $value)
            && strlen($value) >= 10;
    }

    public function message(string $field): string
    {
        return "{$field} must contain uppercase, lowercase, number, and be at least 10 chars.";
    }
}
```

---

## Session Management

### Basic Operations

```php
use System\Session\SessionStore;

// Set values
SessionStore::set('user.name', 'John');
SessionStore::set('cart', ['item1', 'item2']);

// Get values (with optional defaults)
$name = SessionStore::get('user.name');
$cart = SessionStore::get('cart', []);

// Check existence
if (SessionStore::has('user.name')) {
    // ...
}

// Remove values
SessionStore::remove('user.name');

// Get all session data
$all = SessionStore::all();

// Clear entire session
SessionStore::flush();
```

### Flash Messages

```php
// Set flash data (auto-removed after read)
SessionStore::setFlash('success', 'User saved successfully!');
SessionStore::setFlash('error', 'Something went wrong.');

// Read flash data (returns and removes)
$success = SessionStore::getFlash('success');
```

### Session Helper Function

```php
// Get value
$name = session('user.name');

// Set value
session('user.name', 'John');

// Get SessionStore instance
$store = session();
```

### Session Adapters

- **NativeSessionAdapter** — Uses PHP's built-in session handling (default)
- **RedisSessionAdapter** — Redis-backed sessions for distributed apps

---

## Caching

### Basic Operations

```php
use System\Utilities\Cache;

// Initialize with adapter
Cache::init(new \System\Utilities\FileCacheAdapter(storage_path('cache')));

// Store a value (TTL in seconds, 0 = forever)
Cache::put('user.1', $user, 3600);

// Retrieve a value
$user = Cache::get('user.1');
$user = Cache::get('user.1', null);  // With default

// Check existence
if (Cache::has('user.1')) {
    // ...
}

// Remove a value
Cache::forget('user.1');

// Clear all cache
Cache::flush();
```

### Remember Pattern

```php
// Cache expensive operations — only executes callback on cache miss
$users = Cache::remember('active_users', 3600, function () {
    return User::where('is_active', true)->get();
});
```

### Cache Tags

```php
// Store with tags
Cache::putWithTags('user.list', $users, 3600, ['users', 'list']);
Cache::putWithTags('user.1', $user, 3600, ['users']);

// Invalidate all caches with a tag
Cache::invalidateTag('users');  // Clears both user.list and user.1
```

### Cache Adapters

- **FileCacheAdapter** — File-based caching (default, no setup required)
- **RedisCacheAdapter** — Redis-based caching (fast, distributed)

### CLI Commands

```bash
php yantra cache:clear      # Clear all cached files
```

---

## Hooks (Event System)

Yantra provides a WordPress-like hooks system with **actions** (fire-and-forget events) and **filters** (data transformation pipelines).

### Actions

```php
use System\Hooks;

// Register an action listener
Hooks::add_action('user.registered', function (User $user) {
    // Send welcome email
    $mail->sendWelcome($user);
}, priority: 10);

Hooks::add_action('user.registered', function (User $user) {
    // Log the event
    Logger::info("New user: {$user->email}");
}, priority: 20);

// Fire the action (all listeners execute in priority order)
Hooks::do_action('user.registered', $user);
```

### Filters

```php
// Register a filter (transforms data through a pipeline)
Hooks::add_filter('post.title', function (string $title) {
    return ucfirst($title);
});

Hooks::add_filter('post.title', function (string $title) {
    return strip_tags($title);
});

// Apply the filter (value passes through all registered callbacks)
$title = Hooks::apply_filter('post.title', $rawTitle);
```

### Named Hooks (For Removal)

```php
// Register with a name
Hooks::add_action('boot', $callback, priority: 10, name: 'setup_database');

// Remove by name
Hooks::removeActionByName('boot', 'setup_database');
```

### Global Helper Functions

```php
// Actions
add_action('user.login', fn($user) => logLogin($user));
do_action('user.login', $user);

// Filters
add_filter('page.title', fn($title) => "My App - {$title}");
$title = apply_filters('page.title', $title);
```

---

## Authentication & Security

### CSRF Protection

```php
use System\Security\Csrf;

// Generate a token (stored in session, expires in 15 minutes)
$token = Csrf::token('login');

// Validate a token
$valid = Csrf::validate($submittedToken, 'login', rotateOnSuccess: true);

// Rotate token (generate new one)
$newToken = Csrf::rotate('login');
```

**In forms:**
```html
<form method="POST" action="/login">
    <?= csrf_field() ?>
    <!-- Outputs: <input type="hidden" name="_csrf" value="..."> -->

    <input type="email" name="email">
    <input type="password" name="password">
    <button type="submit">Login</button>
</form>
```

**In controllers:**
```php
if (!$this->validateCsrf('login')) {
    return $this->error('Invalid CSRF token', 403);
}
```

### JWT Authentication

```php
use System\Security\Jwt\Jwt;

// Encode a JWT
$token = Jwt::encode([
    'user_id' => 1,
    'role'    => 'admin',
    'exp'     => time() + 3600,
], $secretKey);

// Decode a JWT
$payload = Jwt::decode($token, $secretKey);

// Verify token validity
$valid = Jwt::verify($token, $secretKey);
```

### Password Hashing

```php
use System\Security\Password;

$hash = Password::hash('my_password');
$valid = Password::verify('my_password', $hash);
```

### Cryptographic Utilities

```php
use System\Security\Crypto;

$bytes  = Crypto::randomBytes(32);
$hex    = Crypto::randomHex(32);           // 64-char hex string
$hmac   = Crypto::hmacSha256($data, $key);
$equal  = Crypto::hashEquals($known, $user); // Constant-time comparison
```

### Login Throttling

```php
use System\Security\Login\LoginThrottle;

// Check if throttled
if (LoginThrottle::isThrottled($email, maxAttempts: 5, decayMinutes: 1)) {
    return $this->error('Too many login attempts. Try again later.', 429);
}

// Record failed attempt
LoginThrottle::recordFailedAttempt($email);

// Reset on success
LoginThrottle::resetAttempts($email);
```

### Auth Helpers

```php
// Check if user is authenticated
if (auth_check()) {
    $user = auth_user();  // Returns session auth data
    echo $user['name'];
}
```

### Security Headers Middleware

The `SecurityHeadersMiddleware` automatically adds:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `X-XSS-Protection: 1; mode=block`

---

## Mail Service

### Sending Emails

```php
use System\Services\Email\EmailMessage;

$email = (new EmailMessage())
    ->from('noreply@myapp.com', 'My App')
    ->to('user@example.com', 'John Doe')
    ->cc('manager@example.com')
    ->bcc('archive@example.com')
    ->replyTo('support@myapp.com')
    ->subject('Welcome to My App')
    ->html('<h1>Welcome!</h1><p>Thanks for signing up.</p>')
    ->text('Welcome! Thanks for signing up.')
    ->attach('/path/to/file.pdf', 'invoice.pdf')
    ->embedImage('/path/to/logo.png', 'logo-cid');
```

### Transport Adapters

- **SmtpTransport** — Direct SMTP delivery
- **SendGridTransport** — SendGrid API
- **MailgunTransport** — Mailgun API

### Queued Emails

Emails can be sent asynchronously through the queue system with retry logic and exponential backoff.

### Bounce Handling

The `BounceProcessor` handles email bounce events, updating user email status and removing invalid addresses.

---

## Queue System

### Dispatching Jobs

```php
use System\Services\Queue\QueueInterface;

// Push a job to the queue
$queue->push(SendEmailJob::class, [
    'email' => 'user@example.com',
    'template' => 'welcome',
]);

// Delayed job (execute after 60 seconds)
$queue->later(60, ProcessOrderJob::class, [
    'order_id' => 123,
]);
```

### Defining Jobs

```php
use System\Services\Queue\JobHandlerInterface;
use System\Services\Queue\JobContext;

class SendEmailJob implements JobHandlerInterface
{
    public function handle(JobContext $context): void
    {
        $email = $context->payload()['email'];
        $template = $context->payload()['template'];

        // Send the email...
    }
}
```

### Queue Adapters

- **DatabaseQueue** — MySQL/PostgreSQL storage (reliable, no extra infrastructure)
- **FileQueue** — File-based queue (simple, development-friendly)
- **RedisQueue** — Redis-backed (fast, production-ready)

### Retry Strategy

Failed jobs are retried with configurable exponential backoff.

---

## Task Scheduler

### Defining Schedules

```php
use System\Services\Scheduler\Scheduler;

$scheduler = new Scheduler();

// Run daily at 2 AM
$scheduler->task('db:backup', '0 2 * * *');

// Run every 15 minutes
$scheduler->task('cache:clear', '*/15 * * * *');

// Run every Monday at 9 AM
$scheduler->task('reports:weekly', '0 9 * * 1');

// Execute scheduled tasks
$result = $scheduler->run();
```

### CRON Expressions

```
*    *    *    *    *
│    │    │    │    │
│    │    │    │    └── Day of week (0-7, Sun=0 or 7)
│    │    │    └─────── Month (1-12)
│    │    └──────────── Day of month (1-31)
│    └───────────────── Hour (0-23)
└────────────────────── Minute (0-59)
```

---

## Webhooks

Yantra provides a webhook delivery system with:

- Queue-based delivery for reliability
- Automatic retry logic on failure
- HMAC signature verification for security
- Event-based dispatch

---

## CLI Commands (Yantra Console)

The `yantra` CLI tool provides artisan-style commands for common tasks.

### Available Commands

**Scaffolding:**

| Command | Description |
|---------|-------------|
| `make:controller <Name>` | Create a new controller class |
| `make:model <Name>` | Create a new model class |
| `make:migration <name>` | Create a new migration file |
| `make:seeder <Name>` | Create a new seeder class |
| `make:middleware <Name>` | Create a new middleware class |
| `make:command <Name>` | Create a new CLI command |
| `make:service <Name>` | Create a new service class |
| `make:repository <Name>` | Create a new repository class |
| `make:test <Name>` | Create a new test file |
| `make:model-test <Name>` | Create a test for a model |

**Database:**

| Command | Description |
|---------|-------------|
| `migrate` | Run all pending migrations |
| `migrate:rollback` | Rollback the last migration batch |
| `migrate:refresh` | Rollback all and re-run migrations |
| `migrate:status` | Show migration status |
| `db:seed` | Run database seeders |
| `db:check` | Test database connection |
| `db:make-model <table>` | Generate a model from an existing table |

**Cache & Routes:**

| Command | Description |
|---------|-------------|
| `cache:clear` | Clear all application caches |
| `routes:cache` | Compile and cache routes |
| `routes:clear` | Clear the route cache |
| `routes:list` | List all registered routes |

**Other:**

| Command | Description |
|---------|-------------|
| `env:set <KEY> <VALUE>` | Set an environment variable |
| `app:scaffold` | Scaffold a new application |
| `list` | List all available commands |
| `help <command>` | Display help for a command |

### Creating Custom Commands

```bash
php yantra make:command SendNewsletterCommand
```

```php
<?php
namespace App\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;

class SendNewsletterCommand extends AbstractCommand
{
    public function name(): string
    {
        return 'newsletter:send';
    }

    public function description(): string
    {
        return 'Send the weekly newsletter to all subscribers.';
    }

    public function usage(): array
    {
        return [
            'newsletter:send',
            'newsletter:send --dry-run',
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $dryRun = $this->getOpt($in, 'dry-run');

        $out->info('Sending newsletter...');

        // Business logic here...

        $out->success('Newsletter sent to 1,234 subscribers.');
        return 0;
    }
}
```

Custom commands placed in `App/Cli/Commands/` are auto-discovered.

---

## Helper Functions

Yantra provides global helper functions (loaded from `src/System/functions.php`):

### Configuration & Environment

```php
config('app.name')                     // Get config value
config('app.debug', false)             // Get with default
env('APP_ENV', 'production')           // Get environment variable
```

### Path Helpers

```php
base_path('storage/logs')              // Project root path
app_path('Controllers')                // App directory path
storage_path('cache')                  // Storage directory path
public_path('assets/css')             // Public directory path
theme_path('templates')               // Active theme path
```

### URL Helpers

```php
base_url('/dashboard')                 // Full base URL
site_url('/about')                     // Site URL
assets('css/app.css')                  // Asset URL
theme_url('css/style.css')            // Theme asset URL
current_url()                          // Current request URL
```

### Response Helpers

```php
redirect('/dashboard')                 // Redirect response
redirect('/login', 301)                // Permanent redirect
back()                                 // Redirect to previous page
back('/home')                          // With fallback URL
json(['status' => 'ok'])               // JSON response
json(['error' => 'not found'], 404)    // JSON with status code
abort(404)                             // Throw HTTP exception
abort(403, 'Forbidden')               // With custom message
```

### Security Helpers

```php
csrf_token()                           // Get CSRF token
csrf_field()                           // HTML hidden input with CSRF token
e($value)                              // Escape HTML
esc_attr($value)                       // Escape HTML attribute
esc_url($url)                          // Escape/sanitize URL
```

### Session & Auth Helpers

```php
session('user.name')                   // Get session value
session('user.name', 'John')           // Set session value
old('email')                           // Get old form input (after redirect)
auth_user()                            // Get authenticated user data
auth_check()                           // Check if authenticated
```

### Route Helpers

```php
route('users.show', ['id' => 5])       // Generate named route URL
path_is('/dashboard')                  // Check if current path matches
path_starts('/admin')                  // Check if path starts with prefix
```

### Collection Helper

```php
$collection = collect($users);
$names = collect($users)->pluck('name')->unique()->sort()->values();
```

### Utility Helpers

```php
now()                                  // Current datetime (Y-m-d H:i:s)
dd($var1, $var2)                       // Dump and die (dev only)
normalize_email('  John@Example.COM ') // → john@example.com
pick_keys($array, ['name', 'email'])   // Pick specific keys from array
```

---

## Testing

### Setup

Yantra uses **PHPUnit** for testing with **Mockery** for mocking.

```xml
<!-- phpunit.xml -->
<phpunit bootstrap="tests/bootstrap.php">
    <testsuites>
        <testsuite name="Unit">
            <directory>Tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>Tests/Feature</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_DRIVER" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
    </php>
</phpunit>
```

### Creating Tests

```bash
php yantra make:test UserServiceTest
php yantra make:model-test User
```

### Writing Tests

```php
<?php
namespace Tests\Unit;

use System\Testing\TestCase;

class UserServiceTest extends TestCase
{
    public function test_can_create_user(): void
    {
        $service = new UserService();
        $user = $service->create([
            'name' => 'John',
            'email' => 'john@test.com',
        ]);

        $this->assertNotNull($user->id);
        $this->assertEquals('John', $user->name);
    }
}
```

### HTTP Testing

```php
use System\Testing\TestClient;
use System\Testing\TestResponse;

class UserControllerTest extends TestCase
{
    public function test_index_returns_users(): void
    {
        $client = new TestClient($this->app);

        $response = $client->get('/users');

        $response->assertStatus(200);
        $response->assertJson(['data' => [...]]);
    }

    public function test_store_requires_auth(): void
    {
        $response = $this->client->post('/users', ['name' => 'John']);
        $response->assertStatus(401);
    }
}
```

### Testing Utilities

- **TestCase** — Base class with setup/teardown lifecycle
- **TestClient** — HTTP request simulation
- **TestResponse** — Response assertions (`assertStatus`, `assertJson`, `assertRedirect`)
- **TestKernel** — Isolated test environment
- **Sandboxes** — Isolated state for DB, Session, Cache, Filesystem
- **ClockFake** — Mock system time
- **FixtureManager** — Test data management

### Running Tests

```bash
composer test            # Run all tests
./vendor/bin/phpunit     # Run with PHPUnit directly
```

---

## Logging

```php
use System\Contracts\LoggerInterface;

// Via DI container
class UserController extends BaseController
{
    public function __construct(
        Request $request,
        Response $response,
        private LoggerInterface $logger
    ) {
        parent::__construct($request, $response);
    }

    public function store(): Response
    {
        $this->logger->debug('Creating user', ['email' => $email]);

        try {
            // ...
        } catch (\Throwable $e) {
            $this->logger->error('User creation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

Log levels: `debug`, `error`

Default log location: `storage/logs/app.log`

---

## Error Handling

### ErrorHandler

Yantra automatically converts exceptions to appropriate HTTP responses:

- **Development mode:** Full stack trace, file paths, and debug info
- **Production mode:** Generic error messages (no information leakage)
- **API requests:** JSON error responses
- **Web requests:** HTML error pages

### Exception Types

| Exception | Purpose |
|-----------|---------|
| `DatabaseException` | Database connection/query errors |
| `QueryException` | SQL query execution failures |
| `ValidationException` | Validation rule failures |
| `HttpException` | HTTP error responses (404, 403, etc.) |
| `CliException` | CLI command errors |

### Reporting Errors in Controllers

```php
try {
    // risky operation
} catch (\Throwable $e) {
    $this->report($e);  // Log the error
    return $this->handleException($e, 500);
}
```

---

## Theme Management

Yantra supports dynamic theme switching:

```php
use System\Theme\ThemeManager;

$themeManager = new ThemeManager();
$themeManager->setTheme('dark-mode');

// Check if a view exists in the theme
if ($themeManager->hasView('homepage')) {
    $html = $themeManager->view('homepage', $data);
}
```

Themes live in the `themes/` directory. Configure the active theme:

```php
// App/Config/app.php
return [
    'theme' => [
        'active' => env('APP_THEME', 'default'),
    ],
];
```

---

## Reporting & CSV Imports

### Reports

```php
use System\Services\Reporting\CallableReport;

$report = new CallableReport('monthly_sales', function ($params) {
    return Order::where('status', 'completed')
        ->whereBetween('created_at', [$params['start'], $params['end']])
        ->get();
});

// Export formats: CSV, JSON, Excel
```

### CSV Imports

```php
use System\Services\Imports\ImportManager;

$manager = new ImportManager();
$result = $manager->import($definition);

// Features:
// - Row-by-row validation
// - Batch processing
// - Rollback support on failure
// - Progress tracking
```

---

## Dependency Injection Container

Yantra includes a native DI container — no external DI library required.

### Registering Services

```php
// config/dependencies.php
return [
    // Factory closure (lazy instantiation)
    UserService::class => function (ContainerInterface $c) {
        return new UserService(
            $c->get(Database::class),
            $c->get(LoggerInterface::class)
        );
    },

    // Interface binding
    CacheInterface::class => function () {
        return new FileCacheAdapter(storage_path('cache'));
    },
];
```

### Resolving Services

```php
// Explicit resolution
$userService = $container->get(UserService::class);

// Automatic resolution (via reflection)
// The container auto-wires constructor dependencies
$controller = $container->build(UserController::class);
```

### Interface Bindings

```php
// config/dependencies.php
return [
    ConfigInterface::class   => fn() => Config::getInstance(),
    DatabaseInterface::class => fn() => Database::getInstance()->getAdapter(),
    HooksInterface::class    => fn() => Hooks::getInstance(),
    SessionInterface::class  => fn() => SessionStore::getInstance(),
];
```

---

## Architecture & Design Patterns

### Design Patterns Used

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

### Key Architectural Decisions

1. **Zero production dependencies** — The framework avoids heavy dependency trees entirely.
2. **Static facades with instances** — Public APIs use static facades (`Config::get()`, `Cache::put()`) backed by swappable instance-based implementations.
3. **Scope-aware routing** — Separate route compilation and middleware stacks for `web`, `api`, and `admin` scopes.
4. **Performance-first routing** — Static routes use O(1) hash lookup; dynamic routes use compiled regex. Routes are cached per HTTP method.
5. **Security by default** — Global middleware pipeline includes request normalization, security headers, cookie hardening, CSRF protection, and audit logging.
6. **Database agnostic** — Supports MySQL, MariaDB, PostgreSQL, and SQLite through PDO abstraction.

---

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Commit your changes: `git commit -m 'Add my feature'`
4. Push to the branch: `git push origin feature/my-feature`
5. Open a Pull Request

### Running Tests

```bash
composer test
```

---

## License

Yantra Framework is open-source software licensed under the [MIT License](LICENSE).
