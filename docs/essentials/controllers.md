# Controllers

Controllers group related request-handling logic into classes. Every application controller extends `System\Core\BaseController`, which provides a rich set of response helpers — view rendering, JSON envelopes, redirects, and a hybrid responder — plus convenient access to the current request. Controllers are resolved through the DI container, so constructor dependencies are injected automatically.

```php
<?php
namespace App\Controllers;

use System\Core\BaseController;
use System\Http\Response;

class HomeController extends BaseController
{
    public function index(): Response
    {
        return $this->render('home', ['title' => 'Welcome']);
    }
}
```

## Creating Controllers

Generate a controller with the CLI:

```bash
php yantra make:controller UserController
```

## Response Helpers

`BaseController` provides helpers for every common response shape:

| Helper | Purpose |
|--------|---------|
| `render($view, $data, $layout)` | Render a view (optionally inside a layout) |
| `success($data, $status, $message)` | JSON success envelope |
| `error($message, $status)` | JSON error envelope |
| `validationError($errors)` | JSON validation-failure response (422) |
| `redirect($url, $status)` | HTTP redirect (302 by default) |
| `redirectAfterPost($url)` | 303 See Other — correct Post-Redirect-Get |
| `respond($redirect, $data)` | Hybrid: JSON for AJAX, redirect for forms |

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
        $user = User::findModel($id) ?? abort(404);
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

## Accessing Request Data

Inside a controller, `$this->req()` returns the current `Request`. Helpers like `isPost()` and `methodIs()` check the HTTP method, and `validateCsrf()` verifies the CSRF token for a given scope.

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

See [Requests](/essentials/requests) for the full `Request` API.

## Constructor Dependency Injection

Controllers are resolved through the DI container, so any type-hinted constructor dependency is injected automatically. Always pass `Request` and `Response` up to the parent constructor.

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

::: warning Gotchas
- If you define a constructor, you must call `parent::__construct($request, $response)` — the base controller stores the request/response pair that all helpers depend on.
- Use `redirectAfterPost()` (HTTP 303) after form submissions, not plain `redirect()`, so browsers re-request with GET and don't resubmit the form on refresh.
:::

## Related

- [Routing](/essentials/routing)
- [Requests](/essentials/requests)
- [Responses](/essentials/responses)
- [Views](/essentials/views)
- [Validation](/essentials/validation)
- [Container](/features/container)
