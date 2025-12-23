<?php
declare(strict_types=1);

namespace System\Utilities;

use SessionHandlerInterface;

final class RedisSessionHandler implements SessionHandlerInterface
{
    private object $redis;
    private string $prefix;
    private int $ttl;

    /**
     * @param object $redisClient  ext-redis client or a compatible client exposing get/setex/del/expire
     * @param string $prefix       Redis key prefix for sessions
     * @param int    $ttl          Session TTL seconds
     */
    public function __construct(object $redisClient, string $prefix = 'sess:', int $ttl = 3600)
    {
        $this->redis  = $redisClient;
        $this->prefix = $prefix;
        $this->ttl    = $ttl;
    }

    public function open(string $savePath, string $sessionName): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        try {
            $data = $this->redis->get($this->prefix . $id);
            if ($data === null || $data === false) {
                return '';
            }

            // Sliding expiration on read (optional but common)
            try {
                $this->redis->expire($this->prefix . $id, $this->ttl);
            } catch (\Throwable $e) {
                // ignore
            }

            return (string)$data;
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            // Store raw PHP session-serialized string as provided by PHP session engine
            $this->redis->setex($this->prefix . $id, $this->ttl, $data);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->redis->del($this->prefix . $id);
        } catch (\Throwable $e) {
            // ignore
        }
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis TTL handles GC automatically.
        return 0;
    }
}
