<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;
use System\Security\Auth\Auth;

final class GuestOnlyMiddleware
{
    public function __construct(private string $redirectTo = '/')
    {
    }

    public function __invoke(Request $req, Response $res, callable $next, array $params): void
    {
        if (Auth::check()) {
            http_response_code(302);
            header('Location: ' . $this->redirectTo, true, 302);
            return;
        }
        $next();
    }
}
