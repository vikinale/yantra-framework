<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;

final class CsrfMiddleware
{
    public function __invoke(Request $req, Response $res, callable $next, array $params): Response
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $unsafe = in_array($method, ['POST','PUT','PATCH','DELETE'], true);
        if ($unsafe) {
            $sent = $req->header('X-CSRF-Token') ?? $req->input('_csrf', '');
            if (!\System\Security\Csrf::validate($sent)) {
                return $this->deny($res);
            }
        }

        return $next($req, $res);
    }

    private function deny(Response $res): Response
    {
        return $res->withStatus(419)
                   ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                   ->text('CSRF validation failed.', 419);
    }

}
