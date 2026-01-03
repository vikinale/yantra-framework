<?php
declare(strict_types=1);

namespace System\Security\Middleware;

final class SessionGuard
{
    public static function ensureStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * Call this AFTER successful login (DB verified).
     */
    public static function onLoginSuccess(int|string $userId, array $roles = []): void
    {
        self::ensureStarted();

        // Prevent session fixation: rotate ID and delete old session.
        session_regenerate_id(true);

        $_SESSION['auth'] = [
            'uid'   => (string)$userId,
            'roles' => array_values(array_unique(array_map('strval', $roles))),
            'iat'   => time(),
        ];

        // Optional: bind session to a weak fingerprint (avoid breaking mobile users too often)
        $_SESSION['auth_fp'] = self::fingerprint();
    }

    public static function logout(): void
    {
        self::ensureStarted();

        // Rotate again to kill the authenticated session id
        session_regenerate_id(true);

        unset($_SESSION['auth'], $_SESSION['auth_fp']);
    }

    /**
     * Validate session fingerprint (optional defense-in-depth).
     * Returns false if session looks hijacked.
     */
    public static function validateFingerprint(): bool
    {
        self::ensureStarted();

        if (!isset($_SESSION['auth'])) return true; // only validate when logged in
        $expected = (string)($_SESSION['auth_fp'] ?? '');
        if ($expected === '') return true;

        return hash_equals($expected, self::fingerprint());
    }

    private static function fingerprint(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        // Use partial IP to reduce breakage under NAT; you can remove IP entirely if needed.
        $ipPrefix = preg_replace('/\.\d+$/', '.0', $ip);

        return hash('sha256', $ua . '|' . $ipPrefix);
    }
}