<?php

namespace System\Utilities\Cache;

interface CacheAdapterInterface
{
    public function put(string $key, $value, int $ttl = 0): bool;

    public function get(string $key, $default = null);

    public function has(string $key): bool;

    public function forget(string $key): bool;

    public function increment(string $key, int $amount = 1);

    public function decrement(string $key, int $amount = 1);

    public function flush(): bool;

    // Tag support (optional but implemented here)
    public function putWithTags(string $key, $value, int $ttl, array $tags = []): bool;

    public function invalidateTag(string $tag): bool;
}
