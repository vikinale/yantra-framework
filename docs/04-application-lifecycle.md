# 4. Application Lifecycle

## 4.1 Lifecycle steps
1. Load Composer autoloader
2. Load configuration
3. Configure error handling based on environment
4. Create Request and Response
5. Router matches request path + method
6. Execute middleware stack
7. Invoke controller action
8. Emit response headers + body

```text
index.php → Request → Router → Middleware → Controller → Response → send()
```

## 4.2 Environment behavior
- Development: verbose error display and logging.
- Production: log details, return sanitized messages.

## 4.3 Failure modes
- 404: no route match
- 405: method not allowed (if supported)
- 400: validation failure
- 500: uncaught exceptions

### Central exception handling example
```php
try {
    $router->dispatch($request, $response);
} catch (ValidationException $e) {
    $response->json(['errors' => $e->getErrors()], 400)->send();
} catch (Throwable $e) {
    $response->json(['error' => 'Server Error'], 500)->send();
}
```
