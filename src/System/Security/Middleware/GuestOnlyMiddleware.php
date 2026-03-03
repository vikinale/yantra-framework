<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;

final class GuestOnlyMiddleware
{
    private string $redirectTo = '/';

    public function __construct(string $redirectTo = '/')
    {
        $this->redirectTo = $redirectTo;
    }

    public function __invoke(Request $req, Response $res, callable $next, array $params): Response
    {
        SessionGuard::ensureStarted();
        $auth = $_SESSION['auth'] ?? null;

        if (is_array($auth) && !empty($auth['uid'])) {
            return $res->redirect($this->redirectTo);
        }

        return $next($req, $res);
    }

    public function setRedirectTo(string $redirectTo): GuestOnlyMiddleware
    {
        $this->redirectTo = $redirectTo;
        return $this;
    }

    public function getRedirectTo(): string
    {
        return $this->redirectTo;
    }
}
