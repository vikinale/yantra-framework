<?php

namespace System\Utilities\Cache;

class RedisCacheAdapter implements CacheAdapterInterface
{
    protected $client;
    protected string $prefix;
    protected int $defaultTtl;

    /**
     * $client: \Redis instance or any object implementing the used methods.
     * $prefix: key prefix for isolation, e.g. 'yantra:cache:'
     */
    public function __construct($client, string $prefix = 'yantra:cache:', int $defaultTtl = 0)
    {
        $this->client = $client;
        $this->prefix = $prefix;
        $this->defaultTtl = $defaultTtl;
    }

    protected function pkey(string $key): string
    {
        return $this->prefix . $key;
    }

    public function put(string $key, $value, int $ttl = 0): bool
    {
        $v = serialize($value);
        $p = $this->pkey($key);
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        if ($ttl > 0) {
            return (bool)$this->client->setex($p, $ttl, $v);
        }
        // set without expire
        return (bool)$this->client->set($p, $v);
    }

    public function get(string $key, $default = null)
    {
        $p = $this->pkey($key);
        $v = $this->client->get($p);
        if ($v === false || $v === null) return $default;
        $decoded = $this->safeUnserialize((string)$v);
        return $decoded === null ? $default : $decoded;
    }

    public function has(string $key): bool
    {
        $p = $this->pkey($key);
        try {
            return (bool)$this->client->exists($p);
        } catch (\Throwable $e) {
            return $this->get($key, null) !== null;
        }
    }

    public function forget(string $key): bool
    {
        $p = $this->pkey($key);
        return (bool)$this->client->del($p);
    }

    public function increment(string $key, int $amount = 1)
    {
        $p = $this->pkey($key);
        // use numeric increment when possible
        try {
            if ($amount >= 0) {
                return $this->client->incrBy($p, $amount);
            } else {
                return $this->client->decrBy($p, abs($amount));
            }
        } catch (\Throwable $e) {
            // fallback: get, compute, put
            $v = $this->get($key, 0);
            if (!is_numeric($v)) return false;
            $v = $v + $amount;
            $this->put($key, $v, 0);
            return $v;
        }
    }

    public function decrement(string $key, int $amount = 1)
    {
        return $this->increment($key, -$amount);
    }

    public function flush(): bool
    {
        try {
            // careful: flush entire DB; only recommended for dedicated cache DB
            $this->client->flushDB();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ----------------
     * Tags implementation using Redis sets
     * For each tag we maintain a set: prefix . 'tag:' . tag => set of keys
     * ---------------- */
    public function putWithTags(string $key, $value, int $ttl, array $tags = []): bool
    {
        $ok = $this->put($key, $value, $ttl);
        if (!$ok) return false;
        foreach ($tags as $tag) {
            $this->client->sAdd($this->prefix . 'tag:' . $tag, $key);
            if ($ttl > 0) {
                // set expiry on tag set to at least ttl (best-effort)
                $this->client->expire($this->prefix . 'tag:' . $tag, $ttl);
            }
        }
        return true;
    }

    public function invalidateTag(string $tag): bool
    {
        $setKey = $this->prefix . 'tag:' . $tag;
        $members = $this->client->sMembers($setKey);
        $ok = true;
        foreach ($members as $k) {
            $ok = $ok && $this->forget($k);
        }
        $this->client->del($setKey);
        return $ok;
    }

    private function safeUnserialize(string $value): mixed
    {
        try {
            return unserialize($value, ['allowed_classes' => false]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
