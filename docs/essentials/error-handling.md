# Error Handling & Logging

Yantra converts uncaught exceptions into appropriate HTTP responses automatically: full debug detail in development, safe generic messages in production, and JSON for API requests. Inside controllers you get `report()` and `handleException()` hooks, the `abort()` helper for short-circuiting with an HTTP error, and a `LoggerInterface` you can inject anywhere via the DI container.

```php
try {
    // risky operation
} catch (\Throwable $e) {
    $this->report($e);  // Log the error
    return $this->handleException($e, 500);
}
```

## The ErrorHandler

The framework's error handler adapts its output to the environment and the request type:

- **Development mode:** Full stack trace, file paths, and debug info
- **Production mode:** Generic error messages (no information leakage)
- **API requests:** JSON error responses
- **Web requests:** HTML error pages

## Exception Types

| Exception | Purpose |
|-----------|---------|
| `DatabaseException` | Database connection/query errors |
| `QueryException` | SQL query execution failures |
| `ValidationException` | Validation rule failures |
| `HttpException` | HTTP error responses (404, 403, etc.) |
| `CliException` | CLI command errors |

## The abort() Helper

`abort()` immediately stops execution with an HTTP error status. It is declared `never`, so it can be used as an expression fallback:

```php
// Signature: abort(int $code, string $message = ''): never

$user = User::findModel($id) ?? abort(404);

abort(403, 'You do not have access to this resource.');
```

## Handling Exceptions in Controllers

`BaseController` provides two hooks:

- `report(Throwable $e)` — logs the error (via `error_log()` by default; override it to integrate your own logger).
- `handleException(Throwable $e, int $status = 500)` — logs the message and returns a safe response: a JSON error envelope for AJAX/JSON requests, plain-text `Internal Server Error` otherwise. Exception details are never leaked to the client.

```php
public function store(): Response
{
    try {
        // risky operation
    } catch (\Throwable $e) {
        $this->report($e);  // Log the error
        return $this->handleException($e, 500);
    }
}
```

```php
// Override report() to route errors into your logger
protected function report(Throwable $e): void
{
    $this->logger->error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
}
```

## Logging

Inject `System\Contracts\LoggerInterface` through the DI container:

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

- Log levels: `debug`, `error`
- Default log location: `storage/logs/app.log`

## Custom Error Pages

Register route-level handlers for specific HTTP status codes — see [Error Routes](/essentials/routing#error-routes):

```php
$r->error(404, function ($req, $res) {
    return $res->html('<h1>Page Not Found</h1>', 404);
});
```

::: warning Gotchas
- Never run production with development mode enabled — the dev error handler prints stack traces and file paths to the client.
- `handleException()` always returns a generic message to the client by design; put the details you need in logs via `report()` or the injected logger, not in the response.
:::

## Related

- [Routing](/essentials/routing) — error routes
- [Controllers](/essentials/controllers)
- [Responses](/essentials/responses)
- [Validation](/essentials/validation)
- [Container](/features/container)
