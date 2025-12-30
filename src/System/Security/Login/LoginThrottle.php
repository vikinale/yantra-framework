<?php
declare(strict_types=1);

namespace System\Security\Login;

final class LoginThrottle
{
    /**
     * Check if a login attempt should be blocked.
     */
    public static function isBlocked(string $ip, string $identifier, int $maxFails = 8, int $windowSeconds = 600): bool
    {
        $key = self::key($ip, $identifier);
        $data = self::get($key);

        if (!$data) return false;

        $now = time();

        // Window expired => reset
        if (($data['start'] ?? 0) + $windowSeconds < $now) {
            self::delete($key);
            return false;
        }

        $fails = (int)($data['fails'] ?? 0);
        return $fails >= $maxFails;
    }

    /**
     * Call on failed login.
     * Adds delay and increments fail count.
     */
    public static function onFailure(string $ip, string $identifier, int $windowSeconds = 600): void
    {
        // Always add small delay (works even without APCu)
        self::delay();

        $key = self::key($ip, $identifier);
        $data = self::get($key);

        $now = time();

        if (!$data || !isset($data['start'])) {
            $data = ['start' => $now, 'fails' => 1];
        } else {
            $data['fails'] = (int)$data['fails'] + 1;
        }

        self::set($key, $data, $windowSeconds);
    }

    /**
     * Call on successful login.
     * Clears throttling state.
     */
    public static function onSuccess(string $ip, string $identifier): void
    {
        $key = self::key($ip, $identifier);
        self::delete($key);
    }

    private static function delay(): void
    {
        // 0.8s–1.5s random delay to slow bots
        $ms = random_int(800, 1500);
        usleep($ms * 1000);
    }

    private static function key(string $ip, string $identifier): string
    {
        $id = strtolower(trim($identifier));
        return 'yantra_login:' . hash('sha256', $ip . '|' . $id);
    }

    /** @return array{start:int,fails:int}|null */
    private static function get(string $key): ?array
    {
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $ok = false;
            $v = apcu_fetch($key, $ok);
            return ($ok && is_array($v)) ? $v : null;
        }

        // No storage allowed => no cross-request tracking.
        return null;
    }

    private static function set(string $key, array $value, int $ttl): void
    {
        if (function_exists('apcu_store') && ini_get('apc.enabled')) {
            apcu_store($key, $value, $ttl);
        }
    }

    private static function delete(string $key): void
    {
        if (function_exists('apcu_delete') && ini_get('apc.enabled')) {
            apcu_delete($key);
        }
    }
}
