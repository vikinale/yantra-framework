<?php
declare(strict_types=1);

namespace System\Testing\Sandbox;

use System\Utilities\Cache\CacheAdapterInterface;

// Force autoload of System\Utilities\Cache to make interface available
if (!interface_exists(CacheAdapterInterface::class)) {
    class_exists(\System\Utilities\Cache::class);
}

class ArrayCacheAdapter implements CacheAdapterInterface
{
    private array $storage = [];

    public function put(string $key, $value, int $ttl = 0): bool
    {
        // Ignore TTL for simple array cache or store validUntil
        $this->storage[$key] = [
            'value' => $value,
            'expires' => $ttl > 0 ? time() + $ttl : null
        ];
        return true;
    }

    public function get(string $key, $default = null)
    {
        if (!isset($this->storage[$key])) {
            return $default;
        }

        $item = $this->storage[$key];
        if ($item['expires'] !== null && time() >= $item['expires']) {
            unset($this->storage[$key]);
            return $default;
        }

        return $item['value'];
    }

    public function has(string $key): bool
    {
        return $this->get($key, null) !== null;
    }

    public function forget(string $key): bool
    {
        unset($this->storage[$key]);
        return true;
    }

    public function increment(string $key, int $amount = 1)
    {
        $val = $this->get($key, 0);
        if (!is_numeric($val)) return false;
        $val += $amount;
        $this->put($key, $val, 0);
        return $val;
    }

    public function decrement(string $key, int $amount = 1)
    {
        return $this->increment($key, -$amount);
    }

    public function flush(): bool
    {
        $this->storage = [];
        return true;
    }

    public function putWithTags(string $key, $value, int $ttl, array $tags = []): bool
    {
        // Simple tag support: ignore tags for retrieval, but allow storage
        // To implement cache clearing by tag, we'd need an index. 
        // For array cache, flushing everything is often acceptable in tests, 
        // but let's just store simple values.
        return $this->put($key, $value, $ttl);
    }

    public function invalidateTag(string $tag): bool
    {
        // Naive: flush all? Or just return true?
        // Requirement says "In-memory session + cache, reset per case".
        // So flush is fine.
        return true;
    }
}
