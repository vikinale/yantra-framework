# 8. Controllers

## 8.1 Role
Controllers orchestrate input validation and domain/service calls, then produce a Response.

## 8.2 Principles
- Keep controllers thin.
- Do not access superglobals.
- Delegate business logic to services.
- Return Response objects.

## 8.3 Example controller
```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use System\Http\Request;
use System\Http\Response;

final class UserController
{
    public function show(Request $request, Response $response, string $id): Response
    {
        $request->validate([
            'id' => 'required|numeric',
        ]);

        $user = [
            'id' => (int) $id,
            'name' => 'Example User',
        ];

        return $response->json(['data' => $user], 200);
    }
}
```

## 8.4 Anti-patterns
- SQL queries inside controllers
- echo/print output
- reading configuration everywhere (inject services/config instead)
