<?php
declare(strict_types=1);

namespace System\Security;

use System\Utilities\SessionStore;

final class Csrf
{
    /**
     * Default token TTL (seconds).
     * Adjust as needed (e.g., 900 = 15 minutes, 3600 = 1 hour).
     */
    private const DEFAULT_TTL = 900;

    /**
     * Get or create a CSRF token for a given key.
     * Store is in session under: csrf.<key>
     *
     * Time-based: if stored token is missing OR expired, a new one is minted.
     */
    public static function token(string $key = 'default', int $bytes = 32, int $ttlSeconds = self::DEFAULT_TTL): string
    {
        $storeKey = self::storeKey($key);

        $payload = SessionStore::get($storeKey);
        if (is_array($payload)) {
            $token    = $payload['token'] ?? null;
            $issuedAt = $payload['issued_at'] ?? null;
            $ttl      = $payload['ttl'] ?? null;

            if (is_string($token) && $token !== '' && is_int($issuedAt) && $issuedAt > 0) {
                $ttl = is_int($ttl) && $ttl > 0 ? $ttl : max(1, $ttlSeconds);
                if (!self::isExpired($issuedAt, $ttl)) {
                    return $token;
                }
            }
        } elseif (is_string($payload) && $payload !== '') {
            // Backward compatibility: older versions stored a raw token string.
            // Treat it as expired and replace with the new structured payload.
        }

        $token = Crypto::randomHex($bytes);
        self::store($storeKey, $token, max(1, $ttlSeconds));

        return $token;
    }

    /**
     * Validate token, optionally rotate on success.
     *
     * Time-based: token must match AND not be expired.
     */
    public static function validate(
        string $providedToken,
        string $key = 'default',
        bool $rotateOnSuccess = true,
        int $ttlSeconds = self::DEFAULT_TTL
    ): bool {
        $providedToken = trim($providedToken);
        if ($providedToken === '') {
            return false;
        }

        $storeKey = self::storeKey($key);
        $payload  = SessionStore::get($storeKey);

        $expected = null;
        $issuedAt = null;
        $ttl      = null;

        if (is_array($payload)) {
            $expected = $payload['token'] ?? null;
            $issuedAt = $payload['issued_at'] ?? null;
            $ttl      = $payload['ttl'] ?? null;
        } elseif (is_string($payload) && $payload !== '') {
            // Backward compatibility: raw token without timestamps.
            // You can either reject it or accept it once. Safer default: reject.
            return false;
        }

        if (!is_string($expected) || $expected === '') {
            return false;
        }
        if (!is_int($issuedAt) || $issuedAt <= 0) {
            return false;
        }

        $ttl = is_int($ttl) && $ttl > 0 ? $ttl : max(1, $ttlSeconds);

        // Expired tokens are always invalid
        if (self::isExpired($issuedAt, $ttl)) {
            return false;
        }

        $ok = Crypto::hashEquals($expected, $providedToken);

        if ($ok && $rotateOnSuccess) {
            // rotate to prevent token replay (and refresh time window)
            self::store($storeKey, Crypto::randomHex(32), $ttl);
        }

        return $ok;
    }

    public static function clear(string $key = 'default'): void
    {
        SessionStore::remove(self::storeKey($key));
    }

    /**
     * Optional helper: remaining seconds until expiry (0 if missing/expired).
     */
    public static function remainingTtl(string $key = 'default'): int
    {
        $payload = SessionStore::get(self::storeKey($key));
        if (!is_array($payload)) return 0;

        $issuedAt = $payload['issued_at'] ?? null;
        $ttl      = $payload['ttl'] ?? null;

        if (!is_int($issuedAt) || $issuedAt <= 0) return 0;
        if (!is_int($ttl) || $ttl <= 0) return 0;

        $expiresAt = $issuedAt + $ttl;
        $remain = $expiresAt - time();
        return $remain > 0 ? $remain : 0;
    }

    private static function store(string $storeKey, string $token, int $ttlSeconds): void
    {
        SessionStore::set($storeKey, [
            'token'     => $token,
            'issued_at' => time(),
            'ttl'       => max(1, $ttlSeconds),
        ]);
    }

    private static function isExpired(int $issuedAt, int $ttlSeconds): bool
    {
        // Expired if now is >= issued_at + ttl
        return time() >= ($issuedAt + max(1, $ttlSeconds));
    }

    private static function storeKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') $key = 'default';
        return 'csrf.' . $key;
    }
}