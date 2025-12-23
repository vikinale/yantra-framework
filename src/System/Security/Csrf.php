<?php
declare(strict_types=1);

namespace System\Security;

use System\Utilities\SessionStore;

final class Csrf
{
    /**
     * Get or create a CSRF token for a given key.
     * Store is in session under: csrf.<key>
     */
    public static function token(string $key = 'default', int $bytes = 32): string
    {
        $storeKey = self::storeKey($key);

        $token = SessionStore::get($storeKey);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = Crypto::randomHex($bytes);
        SessionStore::set($storeKey, $token);

        return $token;
    }

    /**
     * Validate token, optionally rotate on success.
     */
    public static function validate(string $providedToken, string $key = 'default', bool $rotateOnSuccess = true): bool
    {
        $providedToken = trim($providedToken);
        if ($providedToken === '') {
            return false;
        }

        $storeKey = self::storeKey($key);
        $expected = SessionStore::get($storeKey);

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        $ok = Crypto::hashEquals($expected, $providedToken);

        if ($ok && $rotateOnSuccess) {
            // rotate to prevent token replay
            SessionStore::set($storeKey, Crypto::randomHex(32));
        }

        return $ok;
    }

    public static function clear(string $key = 'default'): void
    {
        SessionStore::remove(self::storeKey($key));
    }

    private static function storeKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') $key = 'default';
        return 'csrf.' . $key;
    }
}
