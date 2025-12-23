<?php
declare(strict_types=1);

namespace System\Utilities;

final class RedisSessionAdapter implements SessionAdapterInterface
{
    private object $redis;
    private string $prefix;
    private int $ttl;

    public function __construct(object $redisClient, string $prefix = 'sess:', int $ttl = 3600)
    {
        $this->redis  = $redisClient;
        $this->prefix = $prefix;
        $this->ttl    = $ttl;
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sid = session_id();
        if ($sid === '') return;

        try {
            $raw = $this->redis->get($this->prefix . $sid);
            if ($raw) {
                $data = @unserialize($raw);
                if (is_array($data)) {
                    $_SESSION = $data;
                }
            }
        } catch (\Throwable $e) {
            // ignore (best-effort)
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
        $this->persist();
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
        $this->persist();
    }

    public function all(): array
    {
        return $_SESSION ?? [];
    }

    public function clear(): void
    {
        $_SESSION = [];
        $this->persist();
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $old = session_id();
            session_regenerate_id($deleteOldSession);
            $new = session_id();

            // If old session should be deleted, remove Redis key for old SID
            if ($deleteOldSession && $old !== '' && $old !== $new) {
                try { $this->redis->del($this->prefix . $old); } catch (\Throwable $e) {}
            }

            $this->persist();
        }
    }

    public function destroy(): void
    {
        $sid = session_id();

        $_SESSION = [];

        if ($sid !== '') {
            try { $this->redis->del($this->prefix . $sid); } catch (\Throwable $e) {}
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private function persist(): void
    {
        $sid = session_id();
        if ($sid === '') return;

        try {
            $this->redis->setex($this->prefix . $sid, $this->ttl, serialize($_SESSION));
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
