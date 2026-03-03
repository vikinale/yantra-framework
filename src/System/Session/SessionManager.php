<?php
declare(strict_types=1);

namespace System\Session;

use System\Contracts\SessionAdapterInterface;
use System\Contracts\SessionInterface;
use System\Security\Middleware\SessionGuard;

/**
 * SessionManager — instance-based session management.
 *
 * Holds the session adapter and started flag as instance state.
 * The static `SessionStore` facade delegates to a shared SessionManager
 * instance, so existing `SessionStore::get()` / `SessionStore::set()`
 * calls continue to work without changes.
 */
final class SessionManager implements SessionInterface
{
    private ?SessionAdapterInterface $adapter = null;
    private bool $started = false;

    /* -------------------- Lifecycle -------------------- */

    /**
     * Initialize with an adapter and start the session.
     */
    public function init(?SessionAdapterInterface $adapter = null): void
    {
        if ($adapter !== null) {
            if ($this->started) {
                throw new \RuntimeException('Cannot set session adapter after session has started.');
            }
            $this->adapter = $adapter;
        }

        $this->adapter ??= new NativeSessionAdapter();

        if (!$this->started) {
            $this->adapter->start();
            $this->started = true;
        }
    }

    public function isInit(): bool
    {
        return $this->adapter !== null;
    }

    public function ensureStarted(): void
    {
        if (!$this->started) {
            $this->init();
        }
    }

    public function setAdapter(SessionAdapterInterface $adapter): void
    {
        if ($this->started) {
            throw new \RuntimeException('Cannot change session adapter after session has started.');
        }
        $this->adapter = $adapter;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /* -------------------- Core KV API (dot-notation) -------------------- */

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();

        [$root, $path] = self::parseKey($key);
        if ($path === null) {
            $this->adapter->set($root, $value);
            return;
        }

        $data = $this->adapter->get($root, []);
        if (!is_array($data)) {
            $data = [];
        }

        self::arraySetByPath($data, $path, $value);
        $this->adapter->set($root, $data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        [$root, $path] = self::parseKey($key);
        if ($path === null) {
            return $this->adapter->get($root, $default);
        }

        $data = $this->adapter->get($root, null);
        if (!is_array($data)) {
            return $default;
        }

        return self::arrayGetByPath($data, $path, $default);
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();

        [$root, $path] = self::parseKey($key);
        if ($path === null) {
            return $this->adapter->has($root);
        }

        if (!$this->adapter->has($root)) {
            return false;
        }

        $data = $this->adapter->get($root, null);
        if (!is_array($data)) {
            return false;
        }

        return self::arrayHasByPath($data, $path);
    }

    public function remove(string $key): void
    {
        $this->ensureStarted();

        [$root, $path] = self::parseKey($key);
        if ($path === null) {
            $this->adapter->remove($root);
            return;
        }

        $data = $this->adapter->get($root, null);
        if (!is_array($data)) {
            return;
        }

        if (!self::arrayUnsetByPath($data, $path)) {
            return;
        }

        $this->adapter->set($root, $data);
    }

    public function all(): array
    {
        $this->ensureStarted();
        return $this->adapter->all();
    }

    public function clear(): void
    {
        $this->ensureStarted();
        $this->adapter->clear();
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->ensureStarted();
        $this->adapter->regenerate($deleteOldSession);
    }

    public function destroy(): void
    {
        $this->ensureStarted();
        $this->adapter->destroy();
    }

    /* -------------------- App helpers -------------------- */

    public function loginSuccess(int|string $userId, string $email, array $roles, string $name): void
    {
        SessionGuard::onLoginSuccess($userId, $roles, ['email' => $email, 'name' => $name]);
    }

    /* -------------------- Bags: auth / flash -------------------- */

    /**
     * Auth bag stored under 'auth'.
     * - auth($key, $value) => set
     * - auth($key) => pull (get & remove)
     */
    public function auth(string $key, mixed $value = null): mixed
    {
        if (\func_num_args() >= 2) {
            $this->bagSet('auth', $key, $value);
            return null;
        }
        return $this->bagPull('auth', $key, null);
    }

    public function setFlash(string $key, mixed $value): void
    {
        $this->bagSet('flash', $key, $value);
    }

    public function removeFlash(string $key): void
    {
        $this->bagRemove('flash', $key);
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->bagPull('flash', $key, $default);
    }

    /* -------------------- Bag internals -------------------- */

    private function bagSet(string $bag, string $key, mixed $value): void
    {
        $this->ensureStarted();

        $data = $this->adapter->get($bag, []);
        if (!is_array($data)) {
            $data = [];
        }

        $data[$key] = $value;
        $this->adapter->set($bag, $data);
    }

    private function bagRemove(string $bag, string $key): void
    {
        $this->ensureStarted();

        $data = $this->adapter->get($bag, []);
        if (!is_array($data) || !array_key_exists($key, $data)) {
            return;
        }

        unset($data[$key]);
        $this->adapter->set($bag, $data);
    }

    /** pull = get and remove */
    private function bagPull(string $bag, string $key, mixed $default): mixed
    {
        $this->ensureStarted();

        $data = $this->adapter->get($bag, []);
        if (!is_array($data) || !array_key_exists($key, $data)) {
            return $default;
        }

        $out = $data[$key];
        unset($data[$key]);
        $this->adapter->set($bag, $data);

        return $out;
    }

    /* -------------------- Dot-notation helpers -------------------- */

    /**
     * @return array{0: string, 1: array|null}
     */
    private static function parseKey(string $key): array
    {
        $pos = strpos($key, '.');
        if ($pos === false) {
            return [$key, null];
        }

        $root = substr($key, 0, $pos);
        $rest = substr($key, $pos + 1);

        if ($root === '' || $rest === '') {
            return [$key, null];
        }

        return [$root, explode('.', $rest)];
    }

    private static function arraySetByPath(array &$arr, array $path, mixed $value): void
    {
        $ref =& $arr;
        foreach ($path as $seg) {
            if (!is_array($ref)) {
                $ref = [];
            }
            if (!array_key_exists($seg, $ref) || !is_array($ref[$seg])) {
                $ref[$seg] = $ref[$seg] ?? [];
            }
            $ref =& $ref[$seg];
        }
        $ref = $value;
    }

    private static function arrayGetByPath(array $arr, array $path, mixed $default): mixed
    {
        $ref = $arr;
        foreach ($path as $seg) {
            if (!is_array($ref) || !array_key_exists($seg, $ref)) {
                return $default;
            }
            $ref = $ref[$seg];
        }
        return $ref;
    }

    private static function arrayHasByPath(array $arr, array $path): bool
    {
        $ref = $arr;
        foreach ($path as $seg) {
            if (!is_array($ref) || !array_key_exists($seg, $ref)) {
                return false;
            }
            $ref = $ref[$seg];
        }
        return true;
    }

    private static function arrayUnsetByPath(array &$arr, array $path): bool
    {
        if ($path === []) {
            return false;
        }

        $last = array_pop($path);
        $ref  =& $arr;

        foreach ($path as $seg) {
            if (!is_array($ref) || !array_key_exists($seg, $ref)) {
                return false;
            }
            $ref =& $ref[$seg];
        }

        if (!is_array($ref) || !array_key_exists($last, $ref)) {
            return false;
        }

        unset($ref[$last]);
        return true;
    }

    /* -------------------- Reset -------------------- */

    /**
     * Reset the session manager entirely (useful for test isolation).
     */
    public function resetState(): void
    {
        if ($this->adapter !== null && $this->started) {
            try {
                $this->adapter->destroy();
            } catch (\Throwable $e) {
                // ignore during reset
            }
        }
        $this->adapter = null;
        $this->started = false;
    }
}
