<?php
namespace System;

use System\Utilities\SessionStore;

/**
 * Session façade that delegates to System\Utilities\SessionStore.
 *
 * Keeps the same surface as your original Session class so existing code
 * can keep working while using the pluggable adapter system.
 */
class Session
{
    private int $lifetime;
    private string $path;
    private string $domain;
    private bool $secure;
    private bool $httpOnly;

    public function __construct(
        int $lifetime = 3600,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true
    ) {
        $this->lifetime = $lifetime;
        $this->path = $path;
        $this->domain = $domain;
        $this->secure = $secure;
        $this->httpOnly = $httpOnly;

        // Ensure SessionStore is initialized. If the app already
        // calls SessionStore::init() in bootstrap, this will be a no-op.
        $this->ensureStarted();
    }

    protected function ensureStarted(): void
    {
        // SessionStore::init will set a default NativeSessionAdapter if none set.
        SessionStore::init();
    }

    public function set(string $key, $value): void
    {
        SessionStore::set($key, $value);
    }

    public function get(string $key)
    {
        return SessionStore::get($key);
    }

    public function has(string $key): bool
    {
        return SessionStore::has($key);
    }

    public function remove(string $key): void
    {
        SessionStore::remove($key);
    }

    public function clear(): void
    {
        // no direct adapter method for clearing everything except destroy;
        // use all() to get keys and remove them, preserving adapter semantics.
        $all = SessionStore::all();
        foreach ($all as $k => $_) {
            SessionStore::remove((string)$k);
        }
    }

    public function destroy(): void
    {
        SessionStore::destroy();
        // try to remove cookie if using native sessions (best-effort)
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? false);
        }
    }

    public function regenerateId(bool $deleteOldSession = true): void
    {
        // SessionStore exposes regenerate() which regenerates id with true semantics.
        // We call regenerate() unconditionally — adapters should implement it correctly.
        SessionStore::regenerate();
    }

    public function isLoggedIn(): bool
    {
        return $this->has('user_id');
    }

    public function all() {
        return SessionStore::all();
    }

    // Flash helpers stored under 'flash' key (keeps previous behaviour)
    public static function setFlash(string $key, $value): void
    {
        // read existing flash bag, set and save back
        $flash = SessionStore::get('flash', []);
        if (!is_array($flash)) $flash = [];
        $flash[$key] = $value;
        SessionStore::set('flash', $flash);
    }

    public static function getFlash(string $key)
    {
        $flash = SessionStore::get('flash', []);
        if (!is_array($flash)) {
            return null;
        }
        $value = $flash[$key] ?? null;
        if (array_key_exists($key, $flash)) {
            unset($flash[$key]);
            SessionStore::set('flash', $flash);
        }
        return $value;
    }

    /**
     * Update cookie params for native sessions (best-effort).
     *
     * NOTE: your SessionStore adapters may not support changing cookie params.
     * If you need that feature across adapters, add a setCookieParams() method
     * to your adapter interface and implement it there.
     */
    public function setCookieParams(int $lifetime, string $path, string $domain, bool $secure, bool $httpOnly): void
    {
        $this->lifetime = $lifetime;
        $this->path = $path;
        $this->domain = $domain;
        $this->secure = $secure;
        $this->httpOnly = $httpOnly;

        // Best-effort: if native session is in use, try to update cookie params.
        // This mirrors the behaviour you had previously (restarts native session cookie params).
        if (session_status() === PHP_SESSION_ACTIVE) {
            $id = session_id();
            session_write_close();
            // prefer PHP 7.3+ signature using array options when available
            if (!headers_sent()) {
                session_set_cookie_params([
                    'lifetime' => $lifetime,
                    'path' => $path,
                    'domain' => $domain,
                    'secure' => $secure,
                    'httponly' => $httpOnly,
                    'samesite' => 'Lax',
                ]);
            } else {
                // headers already sent; fallback to setcookie (best-effort)
                setcookie(session_name(), session_id(), time() + $lifetime, $path, $domain, $secure, $httpOnly);
            }
            session_id($id);
            session_start();
        } else {
            // If session not started, SessionStore::init() will set default adapter when called.
            // Nothing else to do here; adapter start will pick up default cookie params.
        }
    }
}
