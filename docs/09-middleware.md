# 9. Middleware

## 9.1 Purpose
Middleware runs before/after controller execution to implement cross-cutting concerns:
- Authentication / Authorization
- CORS
- Rate limiting
- Logging and tracing
- Maintenance mode

## 9.2 Execution model
```text
Request → Middleware1 → Middleware2 → Controller → Response
```

## 9.3 Example middleware
```php
<?php
declare(strict_types=1);

namespace App\Middleware;

use System\Http\Request;
use System\Http\Response;

final class AuthMiddleware
{
    public function handle(Request $request, Response $response, callable $next): Response
    {
        $token = $request->header('Authorization');

        if (!$token) {
            return $response->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request, $response);
    }
}
```

## 9.4 Best practices
- Keep middleware focused and fast.
- Avoid DB work unless required.
- Short-circuit only when appropriate (401/403/maintenance).
