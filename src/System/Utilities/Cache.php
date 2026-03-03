<?php
namespace System\Utilities;

use System\Utilities\Cache\CacheAdapterInterface;
use System\Utilities\Cache\FileCacheAdapter;

/**
 * Cache facade and adapters for Yantra.
 *
 * Usage:
 *   // default (file)
 *   Cache::init(); // optional: pass adapter
 *   Cache::put('k', $value, 60); // ttl seconds
 *   Cache::get('k', 'default');
 *   Cache::remember('k', 60, fn() => computeValue());
 *
 *   // use redis
 *   $redis = new \Redis(); $redis->connect('127.0.0.1', 6379);
 *   Cache::init(new RedisCacheAdapter($redis, 'yantra:cache:'));
 *   Cache::put('k', 'v', 120);
 *
 * Tagging:
 *   Cache::putWithTags('users.list', $value, 3600, ['users', 'list']);
 *   Cache::invalidateTag('users'); // invalidates keys in that tag (adapter-dependent)
 */

final class Cache
{
    protected static ?CacheAdapterInterface $adapter = null;

    public static function init(CacheAdapterInterface $adapter = null): void
    {
        if ($adapter !== null) {
            self::$adapter = $adapter;
        } elseif (self::$adapter === null) {
            // default adapter: file under ./storage/cache
            $base = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
            self::$adapter = new FileCacheAdapter($base);
        }
    }

    protected static function adapter(): CacheAdapterInterface
    {
        if (self::$adapter === null) {
            self::init();
        }
        return self::$adapter;
    }

    public static function setAdapter(CacheAdapterInterface $adapter): void
    {
        self::$adapter = $adapter;
    }

    public static function put(string $key, $value, int $ttl = 0): bool
    {
        return self::adapter()->put($key, $value, $ttl);
    }

    public static function get(string $key, $default = null)
    {
        return self::adapter()->get($key, $default);
    }

    public static function has(string $key): bool
    {
        return self::adapter()->has($key);
    }

    public static function forget(string $key): bool
    {
        return self::adapter()->forget($key);
    }

    /**
     * remember: get or compute and store
     * $ttl in seconds (0 = forever)
     */
    public static function remember(string $key, int $ttl, callable $callback)
    {
        $v = self::get($key, null);
        if ($v !== null) return $v;
        $value = $callback();
        self::put($key, $value, $ttl);
        return $value;
    }

    public static function increment(string $key, int $amount = 1)
    {
        return self::adapter()->increment($key, $amount);
    }

    public static function decrement(string $key, int $amount = 1)
    {
        return self::adapter()->decrement($key, $amount);
    }

    public static function flush(): bool
    {
        return self::adapter()->flush();
    }

    /**
     * Tag support: store a key with tags so it can be invalidated by tag.
     * Not all adapters guarantee perfect atomicity across tags.
     */
    public static function putWithTags(string $key, $value, int $ttl, array $tags = []): bool
    {
        return self::adapter()->putWithTags($key, $value, $ttl, $tags);
    }

    public static function invalidateTag(string $tag): bool
    {
        return self::adapter()->invalidateTag($tag);
    }
}
