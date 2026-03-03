<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;

final class AuthGuardMiddleware
{
    /**
     * params can include:
     * - roles: comma-separated roles e.g. "admin,editor"
     * - redirect: redirect URL for browser flows e.g. "/login"
     */
    public function __invoke(Request $req, Response $res, callable $next, array $params): Response
    {
        SessionGuard::ensureStarted();

        // Optional hijack protection
        if (!SessionGuard::validateFingerprint()) {
            // Destroy auth state on mismatch
            SessionGuard::logout();
            return $this->denyOrRedirect($res, $params, 401, 'Session invalid.');
        }

        $auth = $_SESSION['auth'] ?? null;
        if (!is_array($auth) || empty($auth['uid'])) {
            return $this->denyOrRedirect($res, $params, 401, 'Unauthorized.');
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
                return $this->denyOrRedirect($res, $params, 403, 'Forbidden.');
            }
        }

        return $next($req, $res);
    }

    private function parseRoles(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') return [];
        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    private function denyOrRedirect(Response $res, array $params, int $code, string $message): Response
    {
        $redirect = trim((string)($params['redirect'] ?? ''));

        // For website, redirect is usually better UX.
        if ($redirect !== '') {
            return $res->redirect($redirect, 302);
        }

        return $res->withStatus($code)
                   ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                   ->text($message, $code);
    }
}
