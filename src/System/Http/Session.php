<?php
declare(strict_types=1);

namespace System\Http;

use System\Utilities\SessionStore;

final class Session
{
    public function __construct()
    {
        // Ensure store is initialized (bootstrap should call SessionStore::init() early)
        SessionStore::init();
    }

    public function set(string $key, mixed $value): void
    {
        SessionStore::set($key, $value);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return SessionStore::get($key, $default);
    }

    public function has(string $key): bool
    {
        return SessionStore::has($key);
    }

    public function remove(string $key): void
    {
        SessionStore::remove($key);
    }

    public function all(): array
    {
        return SessionStore::all();
    }

    public function clear(): void
    {
        SessionStore::clear();
    }

    public function regenerateId(bool $deleteOldSession = true): void
    {
        SessionStore::regenerate($deleteOldSession);
    }

    public function destroy(): void
    {
        SessionStore::destroy();
    }

    // Flash bag stored under 'flash'
    public function setFlash(string $key, mixed $value): void
    {
        $flash = SessionStore::get('flash', []);
        if (!is_array($flash)) $flash = [];
        $flash[$key] = $value;
        SessionStore::set('flash', $flash);
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        $flash = SessionStore::get('flash', []);
        if (!is_array($flash)) return $default;

        if (!array_key_exists($key, $flash)) return $default;

        $value = $flash[$key];
        unset($flash[$key]);
        SessionStore::set('flash', $flash);

        return $value;
    }
}
