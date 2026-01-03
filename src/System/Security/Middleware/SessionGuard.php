<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Utilities\SessionStore;

final class SessionGuard
{

    public static function ensureStarted(): void
    {
        SessionStore::ensureStarted();
    }

    /**
     * Call this AFTER successful login (DB verified).
     */
    public static function onLoginSuccess(int|string $userId, array $roles = [],?array $data = []): void
    {
        SessionStore::ensureStarted();
        SessionStore::regenerate();
        SessionStore::set('auth',array_merge($data,[
            'uid'   => (string)$userId,
            'roles' => array_values(array_unique(array_map('strval', $roles))),
            'iat'   => time(),
        ]));
        SessionStore::set('auth_fp',self::fingerprint());
    }    
    public static function logout(): void
    {
        SessionStore::ensureStarted();
        SessionStore::regenerate();
        SessionStore::remove('auth');
        SessionStore::remove('auth_fp');
    }

    /**
     * Validate session fingerprint (optional defense-in-depth).
     * Returns false if session looks hijacked.
     */
    public static function validateFingerprint(): bool
    {
        SessionStore::ensureStarted();
        if(!SessionStore::has('auth')) return true;
        $expected = SessionStore::get('auth_fp','');
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