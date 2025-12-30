<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;
use System\Security\Login\LoginThrottle;
use System\Security\Audit\Audit;

final class LoginThrottleMiddleware
{
    public function __construct(
        private int $maxFails = 8,
        private int $windowSeconds = 600
    ) {}

    public function __invoke(Request $req, Response $res, callable $next, array $params): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $identifier = $this->readIdentifier(); // email/username from POST

        if ($identifier !== '' && LoginThrottle::isBlocked($ip, $identifier, $this->maxFails, $this->windowSeconds)) {
            Audit::log('login_throttled', [
                'ip' => $ip,
                'identifier' => $this->mask($identifier),
                'path' => ($_SERVER['REQUEST_URI'] ?? ''),
            ]);

            http_response_code(429);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Too many login attempts. Please try again later.';
            return;
        }

        $next();
    }

    private function readIdentifier(): string
    {
        // Adjust field name to your login form: email/username
        $v = (string)($_POST['email'] ?? $_POST['username'] ?? '');
        return trim($v);
    }

    private function mask(string $id): string
    {
        // Avoid logging full email/username
        $id = trim($id);
        if ($id === '') return '';
        if (str_contains($id, '@')) {
            [$u, $d] = explode('@', $id, 2);
            $u = substr($u, 0, 2) . '***';
            return $u . '@' . $d;
        }
        return substr($id, 0, 2) . '***';
    }
}

/*
C) App login controller: call throttle hooks

In your login handler:

Before DB check: if middleware already blocks, it won’t reach here.

On failure: call LoginThrottle::onFailure(...)

On success: call LoginThrottle::onSuccess(...)

Example:

use System\Security\Login\LoginThrottle;
use System\Security\Session\SessionGuard;

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$email = trim((string)($_POST['email'] ?? ''));

// DB verify ...
if (!$user || !password_verify($pass, $user->password_hash)) {
    LoginThrottle::onFailure($ip, $email);
    // return error
} else {
    LoginThrottle::onSuccess($ip, $email);
    SessionGuard::onLoginSuccess($user->id, $user->roles ?? []);
    // redirect
}

D) Wire it into routes (website only)

Apply it only to login POST (and optionally GET):

$r->group(['middleware' => ['sec.csrf']], function($r) {

    $r->get('/login',  [LoginController::class, 'show'])
      ->middleware(['sec.guest', 'sec.login_throttle']);

    $r->post('/login', [LoginController::class, 'submit'])
      ->middleware(['sec.guest', 'sec.login_throttle']);

});
*/