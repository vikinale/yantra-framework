# Routing

Yantra routes are defined in closures that receive a `RouteCollector` instance. Static routes resolve via O(1) hash lookup and dynamic routes via compiled regex, with the compiled tables cacheable per HTTP method for production. Route files live in `App/Routes/` — `web.php` for web routes and `api.php` for API routes.

```php
// App/Routes/web.php
use System\Core\Routing\RouteCollector;

return function (RouteCollector $r) {
    $r->get('/', 'HomeController@index');
    $r->get('/users/{id}', 'UserController@show');
    $r->post('/users', 'UserController@store');
};
```

## Defining Routes

Each HTTP verb has a dedicated method. Route parameters use `{name}` placeholders and are passed to your controller method.

```php
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

## Named Routes

Give routes names with `name()`, then generate URLs with the `route()` helper — no more hard-coded paths scattered through views and controllers.

```php
$r->get('/users/{id}', 'UserController@show')->name('users.show');
$r->get('/dashboard', 'DashboardController@index')->name('dashboard');
```

```php
// Generate URLs from route names
$url = route('users.show', ['id' => 5]);          // → /users/5
$url = route('dashboard');                          // → /dashboard
$url = route('users.show', ['id' => 5], ['tab' => 'posts']);  // → /users/5?tab=posts
```

The third argument is an array of query-string parameters.

## Route Groups

Groups share a URL prefix and/or middleware across a set of routes. Groups can be nested — prefixes and middleware accumulate.

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

## Route Middleware

Attach middleware to individual routes with `middleware()`. Parameters are passed via the second argument as an associative array: `->middleware($alias, $params)`.

```php
// Single middleware
$r->get('/profile', 'ProfileController@show')->middleware('auth');

// Multiple middleware (restrict to the admin role via the auth params array)
$r->post('/admin/users', 'Admin\UserController@store')
  ->middleware('auth', ['roles' => 'admin']);

// Middleware with parameters (passed as the second-argument array)
$r->post('/api/data', 'ApiController@store')
  ->middleware('rate.limit', ['limit' => 60, 'window' => 60]);  // 60 requests per 60 seconds
```

See [Middleware](/essentials/middleware) for the built-in aliases and how to write your own.

## Route Model Binding

Register a binding on the router and matching route parameters are automatically resolved into model instances, fetched from the database before your controller runs.

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

The optional third argument to `model()` selects the column used for lookup; it defaults to the model's primary key.

## Error Routes

Register handlers for HTTP error statuses (e.g. 404, 405) directly on the collector:

```php
$r->error(404, function ($req, $res) {
    return $res->html('<h1>Page Not Found</h1>', 404);
});

$r->error(405, function ($req, $res) {
    return $res->html('<h1>Method Not Allowed</h1>', 405);
});
```

## API Routes

Routes defined in `App/Routes/api.php` are automatically prefixed with `/api` — do not add the prefix yourself.

```php
// App/Routes/api.php
return function (RouteCollector $r) {
    $r->get('/users', 'Api\UserController@index');       // → /api/users
    $r->post('/users', 'Api\UserController@store');
    $r->get('/users/{id}', 'Api\UserController@show');
    $r->put('/users/{id}', 'Api\UserController@update');
    $r->delete('/users/{id}', 'Api\UserController@destroy');
};
```

## Route Caching

Routes are compiled and cached for high performance:

```bash
# Cache all routes
php yantra routes:cache

# Clear route cache
php yantra routes:clear

# List all registered routes
php yantra routes:list
```

The cache is split per scope and HTTP method:

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

::: warning Gotchas
- After adding or changing routes in production, run `php yantra routes:clear` (or re-run `routes:cache`) — stale cached routes will keep serving the old definitions.
- API routes get the `/api` prefix automatically; defining `$r->get('/api/users', ...)` inside `api.php` results in `/api/api/users`.
:::

## Related

- [Controllers](/essentials/controllers)
- [Middleware](/essentials/middleware)
- [Requests](/essentials/requests)
- [RouteCollector API](/api/route-collector)
