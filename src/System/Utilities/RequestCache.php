<?php
namespace System\Utilities;

/**
 * RequestCache
 *
 * Small wrapper around the Cache facade that provides an instance-based API for use on Request:
 *   $request->cache()->remember('key', 60, fn() => compute());
 *
 * This keeps controllers/middleware easier to test and avoids calling Cache::static methods directly.
 */
class RequestCache
{
    /**
     * Optional prefix applied to all keys to scope them per-request or per-controller if needed.
     * You can pass a prefix when instantiating.
     *
     * @var string|null
     */
    protected ?string $prefix;

    public function __construct(?string $prefix = null)
    {
        $this->prefix = $prefix ? rtrim($prefix, ':') . ':' : '';
    }

    protected function applyPrefix(string $key): string
    {
        return $this->prefix . $key;
    }

    public function put(string $key, $value, int $ttl = 0): bool
    {
        return Cache::put($this->applyPrefix($key), $value, $ttl);
    }

    public function get(string $key, $default = null)
    {
        return Cache::get($this->applyPrefix($key), $default);
    }

    public function has(string $key): bool
    {
        return Cache::has($this->applyPrefix($key));
    }

    public function forget(string $key): bool
    {
        return Cache::forget($this->applyPrefix($key));
    }

    /**
     * remember: get or compute and store
     *
     * Usage:
     *   $value = $request->cache()->remember('users.all', 300, function() { return compute(); });
     *
     * @param string $key
     * @param int $ttl seconds (0 = forever)
     * @param callable $callback
     * @return mixed
     */
    public function remember(string $key, int $ttl, callable $callback)
    {
        $fullKey = $this->applyPrefix($key);
        return Cache::remember($fullKey, $ttl, $callback);
    }

    public function increment(string $key, int $amount = 1)
    {
        return Cache::increment($this->applyPrefix($key), $amount);
    }

    public function decrement(string $key, int $amount = 1)
    {
        return Cache::decrement($this->applyPrefix($key), $amount);
    }

    public function flush(): bool
    {
        return Cache::flush();
    }

    /**
     * put with tags
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @param array $tags
     * @return bool
     */
    public function putWithTags(string $key, $value, int $ttl, array $tags = []): bool
    {
        // prefix keys, but keep tags raw (tags are not prefixed by default)
        return Cache::putWithTags($this->applyPrefix($key), $value, $ttl, $tags);
    }

    /**
     * Invalidate a tag
     * @param string $tag
     * @return bool
     */
    public function invalidateTag(string $tag): bool
    {
        return Cache::invalidateTag($tag);
    }

    /**
     * Ability to create a scoped RequestCache copy with a different prefix
     * @param string|null $prefix
     * @return RequestCache
     */
    public function withPrefix(?string $prefix): RequestCache
    {
        return new self($prefix);
    }
}