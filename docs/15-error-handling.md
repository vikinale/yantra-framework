# 15. Error Handling & Exceptions

## 15.1 Principles
- Fail fast with meaningful exceptions
- Convert exceptions to HTTP responses centrally
- Do not expose stack traces in production

## 15.2 Exception mapping
- ValidationException → 400
- NotFoundException → 404
- Unauthorized/Forbidden → 401/403
- Throwable → 500

## 15.3 Example handler
```php
try {
    $router->dispatch($request, $response);
} catch (ValidationException $e) {
    $response->json(['errors' => $e->getErrors()], 400)->send();
} catch (Throwable $e) {
    $response->json(['error' => 'Server Error'], 500)->send();
}
```
