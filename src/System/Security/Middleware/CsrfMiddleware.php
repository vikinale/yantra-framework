<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;

final class CsrfMiddleware
{
    private const COOKIE = 'csrf_token';
    private const HEADER = 'X-CSRF-Token';
    private const FIELD  = '_token';

    public function __invoke(Request $req, Response $res, callable $next, array $params): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $unsafe = in_array($method, ['POST','PUT','PATCH','DELETE'], true);

        // Ensure token exists
        if (empty($_COOKIE[self::COOKIE])) {
            $this->setToken($this->generate());
        }

        if ($unsafe) {
            $cookie = $_COOKIE[self::COOKIE] ?? '';
            $sent   = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST[self::FIELD] ?? '');

            if ($cookie === '' || $sent === '' || !hash_equals($cookie, $sent)) {
                $this->deny();
                return;
            }
        }

        $next();
    }

    private function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function setToken(string $token): void
    {
        setcookie(self::COOKIE, $token, [
            'path'     => '/',
            'secure'   => $this->isHttps(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    private function deny(): void
    {
        http_response_code(419);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'CSRF validation failed.';
    }

    private function isHttps(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        );
    }
}
