<?php
namespace System\Utilities;

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

class Cache
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

/* ---------------------------
 * Adapter contract
 * --------------------------- */
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

/* ---------------------------
 * FileCacheAdapter
 * stores files: each cache entry is a file with serialized payload:
 *   ['expires' => timestamp|null, 'value' => mixed, 'tags' => array]
 * Tag index stored at $baseDir/.tags/{tag}.idx as JSON list of keys
 * --------------------------- */
class FileCacheAdapter implements CacheAdapterInterface
{
    protected string $baseDir;
    protected string $tagDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);
        $this->tagDir = $this->baseDir . DIRECTORY_SEPARATOR . '.tags';
        if (!is_dir($this->baseDir)) @mkdir($this->baseDir, 0777, true);
        if (!is_dir($this->tagDir)) @mkdir($this->tagDir, 0777, true);
    }

    protected function keyToPath(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $key);
        $hash = hash('sha256', $key);
        // use a two-level directory for distribution
        $sub = substr($hash, 0, 2);
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $sub;
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return $dir . DIRECTORY_SEPARATOR . $safe . '_' . $hash . '.cache';
    }

    public function put(string $key, $value, int $ttl = 0): bool
    {
        $path = $this->keyToPath($key);
        $expires = $ttl > 0 ? time() + $ttl : null;
        $payload = ['expires' => $expires, 'value' => $value, 'tags' => []];
        $data = serialize($payload);

        $fp = @fopen($path, 'wb');
        if (!$fp) return false;
        if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
        $written = fwrite($fp, $data);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $written !== false;
    }

    public function get(string $key, $default = null)
    {
        $path = $this->keyToPath($key);
        if (!file_exists($path)) return $default;
        $fp = @fopen($path, 'rb');
        if (!$fp) return $default;
        if (!flock($fp, LOCK_SH)) { fclose($fp); return $default; }
        $contents = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $payload = @unserialize($contents);
        if (!is_array($payload) || !array_key_exists('value', $payload)) {
            @unlink($path);
            return $default;
        }
        if ($payload['expires'] !== null && time() >= $payload['expires']) {
            @unlink($path);
            return $default;
        }
        return $payload['value'];
    }

    public function has(string $key): bool
    {
        $path = $this->keyToPath($key);
        if (!file_exists($path)) return false;
        $fp = @fopen($path, 'rb');
        if (!$fp) return false;
        if (!flock($fp, LOCK_SH)) { fclose($fp); return false; }
        $contents = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $payload = @unserialize($contents);
        if (!is_array($payload) || !array_key_exists('value', $payload)) { @unlink($path); return false; }
        if ($payload['expires'] !== null && time() >= $payload['expires']) { @unlink($path); return false; }
        return true;
    }

    public function forget(string $key): bool
    {
        $path = $this->keyToPath($key);
        if (file_exists($path)) {
            return @unlink($path);
        }
        return true;
    }

    public function increment(string $key, int $amount = 1)
    {
        $val = $this->get($key, null);
        if ($val === null) {
            $this->put($key, $amount, 0);
            return $amount;
        }
        if (!is_numeric($val)) {
            return false;
        }
        $new = $val + $amount;
        // preserve previous TTL (not tracked easily) — write with no TTL
        $this->put($key, $new, 0);
        return $new;
    }

    public function decrement(string $key, int $amount = 1)
    {
        return $this->increment($key, -$amount);
    }

    public function flush(): bool
    {
        // delete all files under baseDir (careful)
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->baseDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        $ok = true;
        foreach ($iter as $path) {
            $p = $path->getPathname();
            if (is_file($p)) {
                $ok = $ok && @unlink($p);
            } elseif (is_dir($p) && basename($p) === '.tags') {
                // keep .tags directory empty
                $sub = new \FilesystemIterator($p);
                foreach ($sub as $f) @unlink($f->getPathname());
            }
        }
        return $ok;
    }

    /* ----------------------
     * Tagging
     * ---------------------- */
    public function putWithTags(string $key, $value, int $ttl, array $tags = []): bool
    {
        $path = $this->keyToPath($key);
        $expires = $ttl > 0 ? time() + $ttl : null;
        $payload = ['expires' => $expires, 'value' => $value, 'tags' => $tags];
        $data = serialize($payload);

        $fp = @fopen($path, 'wb');
        if (!$fp) return false;
        if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
        $written = fwrite($fp, $data);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        // update tag index files
        foreach ($tags as $tag) {
            $tagFile = $this->tagDir . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_\-]/', '_', $tag) . '.idx';
            $keys = [];
            if (file_exists($tagFile)) {
                $contents = @file_get_contents($tagFile);
                $keys = $contents ? @json_decode($contents, true) ?? [] : [];
            }
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
                @file_put_contents($tagFile, json_encode($keys, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), LOCK_EX);
            }
        }

        return $written !== false;
    }

    public function invalidateTag(string $tag): bool
    {
        $tagFile = $this->tagDir . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_\-]/', '_', $tag) . '.idx';
        if (!file_exists($tagFile)) return true;
        $contents = @file_get_contents($tagFile);
        $keys = $contents ? @json_decode($contents, true) ?? [] : [];
        $ok = true;
        foreach ($keys as $k) {
            $ok = $ok && $this->forget($k);
        }
        @unlink($tagFile);
        return $ok;
    }
}

/* ---------------------------
 * RedisCacheAdapter
 * requires a Redis-like client. For ext-redis use \Redis instance.
 * Assumes the client supports: get, setex, set, del, incrBy, decrBy, sAdd, sRem, sMembers, expire, exists, flushDB
 * --------------------------- */
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
        return @unserialize($v);
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
}
