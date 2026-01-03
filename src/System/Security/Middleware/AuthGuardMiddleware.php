<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;
use System\Security\SessionGuard;

final class AuthGuardMiddleware
{
    /**
     * params can include:
     * - roles: comma-separated roles e.g. "admin,editor"
     * - redirect: redirect URL for browser flows e.g. "/login"
     */
    public function __invoke(Request $req, Response $res, callable $next, array $params): void
    {
        SessionGuard::ensureStarted();

        // Optional hijack protection
        if (!SessionGuard::validateFingerprint()) {
            // Destroy auth state on mismatch
            SessionGuard::logout();
            $this->denyOrRedirect($params, 401, 'Session invalid.');
            return;
        }

        $auth = $_SESSION['auth'] ?? null;
        if (!is_array($auth) || empty($auth['uid'])) {
            $this->denyOrRedirect($params, 401, 'Unauthorized.');
            return;
        }

        // Role check if requested
        $required = $this->parseRoles($params['roles'] ?? '');
        if ($required !== []) {
            $roles = $auth['roles'] ?? [];
            if (is_string($roles)) $roles = [$roles];
            if (!is_array($roles)) $roles = [];

            $roles = array_map('strval', $roles);

            $ok = false;
            foreach ($required as $r) {
                if (in_array($r, $roles, true)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $this->denyOrRedirect($params, 403, 'Forbidden.');
                return;
            }
        }

        $next();
    }

    private function parseRoles(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') return [];
        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    private function denyOrRedirect(array $params, int $code, string $message): void
    {
        $redirect = trim((string)($params['redirect'] ?? ''));

        // For website, redirect is usually better UX.
        if ($redirect !== '') {
            http_response_code(302);
            header('Location: ' . $redirect, true, 302);
            return;
        }

        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
}