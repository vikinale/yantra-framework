<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Session\SessionStore;

final class SessionGuard
{
    public static function ensureStarted(): void
    {
        // Note: ensureStarted() must be public in SessionStore (it is in your class)
        SessionStore::ensureStarted();
    }

    /**
     * Call this AFTER successful login (DB verified).
     */
    public static function onLoginSuccess(int|string $userId, array $roles = [], ?array $data = []): void
    {
        SessionStore::ensureStarted();
        SessionStore::regenerate(true);

        $payload = is_array($data) ? $data : [];

        // Normalize roles once
        $roles = array_values(array_unique(array_map('strval', $roles)));

        // Keep your canonical format: store whole auth array
        $payload['uid']   = (string)$userId;
        $payload['roles'] = $roles;
        $payload['iat']   = time();

        SessionStore::set('auth', $payload);

        // Store fingerprint within auth namespace (dot-notation)
        SessionStore::set('auth.fp', self::fingerprint());

        // Optional backward compatibility (remove later)
        // SessionStore::set('auth_fp', SessionStore::get('auth.fp', ''));
    }

    public static function logout(): void
    {
        SessionStore::ensureStarted();

        // Remove auth first, then rotate session id
        SessionStore::remove('auth');
        SessionStore::remove('auth.fp'); // in case auth got partially removed
        // Optional legacy cleanup
        SessionStore::remove('auth_fp');

        SessionStore::regenerate(true);
    }

    /**
     * Validate session fingerprint (optional defense-in-depth).
     * Returns false if session looks hijacked.
     */
    public static function validateFingerprint(): bool
    {
        SessionStore::ensureStarted();

        // If not logged in, don't block.
        $uid = SessionStore::get('auth.uid', '');
        if ($uid === '' || $uid === null) {
            return true;
        }

        // Prefer auth.fp (new)
        $expected = SessionStore::get('auth.fp', '');

        // Backward compatibility: older sessions may have auth_fp
        if ($expected === '' || $expected === null) {
            $expected = SessionStore::get('auth_fp', '');
        }

        if (!is_string($expected) || $expected === '') {
            return true;
        }

        return hash_equals($expected, self::fingerprint());
    }

    private static function fingerprint(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        // IPv4: reduce breakage under NAT (keep /24). If you want zero-IP binding, remove IP entirely.
        $ipPrefix = preg_replace('/\.\d+$/', '.0', $ip);

        return hash('sha256', $ua . '|' . $ipPrefix);
    }
}
