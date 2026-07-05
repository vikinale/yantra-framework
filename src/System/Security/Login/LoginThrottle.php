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

    private static function apcuAvailable(): bool
    {
        return function_exists('apcu_fetch')
            && function_exists('apcu_store')
            && function_exists('apcu_delete')
            && ini_get('apc.enabled');
    }

    /** @return array{start:int,fails:int}|null */
    private static function get(string $key): ?array
    {
        if (self::apcuAvailable()) {
            $ok = false;
            $v = apcu_fetch($key, $ok);
            return ($ok && is_array($v)) ? $v : null;
        }

        // Filesystem fallback so throttling still works without APCu.
        return self::fileGet($key);
    }

    private static function set(string $key, array $value, int $ttl): void
    {
        if (self::apcuAvailable()) {
            apcu_store($key, $value, $ttl);
            return;
        }

        self::fileSet($key, $value, $ttl);
    }

    private static function delete(string $key): void
    {
        if (self::apcuAvailable()) {
            apcu_delete($key);
            return;
        }

        self::fileDelete($key);
    }

    /**
     * Directory used by the filesystem fallback store.
     * Created on first write if missing.
     */
    private static function fileDir(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'yantra_login_throttle';
    }

    private static function filePath(string $key): string
    {
        // key() embeds a "yantra_login:" prefix; strip characters that are
        // invalid in filenames (notably ":" on Windows) while preserving the
        // per-key uniqueness of the underlying sha256 hash.
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $key);
        if (!is_string($safe) || $safe === '') {
            $safe = hash('sha256', $key);
        }
        return self::fileDir() . DIRECTORY_SEPARATOR . $safe;
    }

    /**
     * Read the stored state for a key.
     * A file older than its persisted TTL is treated as absent
     * (mirrors APCu window-expiry semantics).
     *
     * @return array{start:int,fails:int}|null
     */
    private static function fileGet(string $key): ?array
    {
        $file = self::filePath($key);

        $fp = @fopen($file, 'r');
        if ($fp === false) {
            return null;
        }

        try {
            if (!flock($fp, LOCK_SH)) {
                return null;
            }

            $raw = stream_get_contents($fp);
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['data'], $decoded['expires'])) {
            return null;
        }

        // Expired => behave as if the entry does not exist.
        if ((int)$decoded['expires'] < time()) {
            self::fileDelete($key);
            return null;
        }

        $data = $decoded['data'];
        if (!is_array($data)) {
            return null;
        }

        return [
            'start' => (int)($data['start'] ?? 0),
            'fails' => (int)($data['fails'] ?? 0),
        ];
    }

    private static function fileSet(string $key, array $value, int $ttl): void
    {
        $dir = self::fileDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }

        $file = self::filePath($key);

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return;
            }

            $payload = json_encode([
                'data'    => [
                    'start' => (int)($value['start'] ?? time()),
                    'fails' => (int)($value['fails'] ?? 0),
                ],
                'expires' => time() + max(1, $ttl),
            ]);

            if ($payload === false) {
                return;
            }

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $payload);
            fflush($fp);
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private static function fileDelete(string $key): void
    {
        $file = self::filePath($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
