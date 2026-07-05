<?php
declare(strict_types=1);

use System\Config;
use System\Hooks;

/*
 * Optional procedural helpers for Yantra apps.
 *
 * Rules:
 * - Core framework classes should not depend on these functions.
 * - Load this file in your application bootstrap if you want the helpers.
 * - Keep helpers small, predictable, and side-effect free.
 */

if (!function_exists('out')) {
    /**
     * CLI stdout helper.
     */
    function out(string $msg): void
    {
        fwrite(STDOUT, $msg . PHP_EOL);
    }
}

if (!function_exists('okFile')) {
    /**
     * Check file exists and is readable.
     */
    function okFile(string $file): bool
    {
        return is_file($file) && is_readable($file);
    }
}

/* --------------------------------------------------------------------------
 | Config / Env
 * -------------------------------------------------------------------------- */

if (!function_exists('config')) {
    /**
     * Get config value with optional default.
     *
     * Usage:
     *   config('app.url')               // get value
     *   config('app.url', 'fallback')   // get value with default
     *
     * To set config values, use Config::set() directly.
     */
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('env')) {
    /**
     * Read environment variables with a default.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $val = getenv($key);
        if ($val === false) {
            return $default;
        }

        // Normalize common string values
        // Note: '0' and '1' are NOT cast to booleans — they are valid numeric strings.
        // Only explicit boolean words are converted.
        $lower = strtolower(trim((string)$val));
        return match ($lower) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $val,
        };
    }
}

/* --------------------------------------------------------------------------
 | Hooks (WordPress-like)
 * -------------------------------------------------------------------------- */

if (!function_exists('add_action')) {
    function add_action(
        string $hook,
        callable $callback,
        int $priority = 10,
        ?string $name = null,
        int $accepted_args = 1
    ): void {
        Hooks::add_action($hook, $callback, $priority, $name, $accepted_args);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        Hooks::do_action($hook, ...$args);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(
        string $hook,
        callable $callback,
        int $priority = 10,
        ?string $name = null,
        int $accepted_args = 1
    ): void {
        Hooks::add_filter($hook, $callback, $priority, $name, $accepted_args);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return Hooks::apply_filter($hook, $value, ...$args);
    }
}

/**
 * Backward compatible alias (your old name).
 */
if (!function_exists('apply_filter')) {
    function apply_filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        return apply_filters($hook, $value, ...$args);
    }
}

/* --------------------------------------------------------------------------
 | Paths
 * -------------------------------------------------------------------------- */


if (!function_exists('theme_path')) {
    function theme_path(string $append = ''): string
    {
        $path = (string)(Config::get('app.theme.active') ?? 'default');
        $path = 'themes/'.trim($path);
        $path = $append === ''
            ? $path
            : $path . DIRECTORY_SEPARATOR . ltrim($append, DIRECTORY_SEPARATOR);
        return(base_path($path));
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $append = ''): string
    {
        $path = base_path('storage');
        return $append === '' ? $path : $path . DIRECTORY_SEPARATOR . ltrim($append, DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('public_path')) {
    function public_path(string $append = ''): string
    {
        $path = base_path('public');
        return $append === '' ? $path : $path . DIRECTORY_SEPARATOR . ltrim($append, DIRECTORY_SEPARATOR);
    }
}

/* --------------------------------------------------------------------------
 | URL helpers
 * -------------------------------------------------------------------------- */

if (!function_exists('is_https')) {
    /**
     * Detect HTTPS, including common reverse proxy headers.
     */
    function is_https(): bool
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        }
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443;
    }
}

if (!function_exists('base_url')) {
    /**
     * Base URL of the application.
     * Priority:
     *  1) Config('app.url') if set (recommended)
     *  2) Derive from current request host/proto + Config('app.site') (subdir)
     */
    function base_url(?string $append = ''): string
    {
        $configured = (string)(Config::get('app.url') ?? '');
        if ($configured !== '') {
            $configured = rtrim($configured, '/');
            if ($append === '' || $append === null) {
                return $configured;
            }
            return $configured . '/' . ltrim((string)$append, '/');
        }

        $protocol = is_https() ? 'https://' : 'http://';
        // Do NOT trust X-Forwarded-Host — it can be spoofed.
        // Use HTTP_HOST or SERVER_NAME only.
        $host = $_SERVER['HTTP_HOST']
            ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        // Applications can set Config::set('app.site', '/subdir')
        $base = rtrim((string)(Config::get('app.site') ?? ''), '/');

        $url = rtrim($protocol . $host . '/' . ltrim($base, '/'), '/');

        if ($append === '' || $append === null) {
            return $url;
        }
        return $url . '/' . ltrim((string)$append, '/');
    }
}

if (!function_exists('site_name')) {
    /**
     * Alias for base_url (kept for familiarity).
     */
    function site_name(?string $append = ''): string
    {
        $configured = (string)(Config::get('app.site') ?? '');
        $configured = rtrim($configured, '/');
        if ($append === '' || $append === null) {
                return $configured;
        }
        return $configured . '/' . ltrim((string)$append, '/');
    }
}

if (!function_exists('site_url')) {
    /**
     * Alias for base_url (kept for familiarity).
     */
    function site_url(?string $append = ''): string
    {
        return base_url($append);
    }
}

if (!function_exists('assets')) {
    /**
     * Public asset URL helper.
     * Default base: /assets
     * Override with Config('app.assets.base') e.g. '/public' or '/assets'
     */
    function assets(?string $append = ''): string
    {
        $base = (string)(Config::get('app.assets.base') ?? 'assets');
        $base = trim($base, '/');

        $root = $base === '' ? base_url() : base_url($base);

        if ($append === '' || $append === null) {
            return $root;
        }
        return $root . '/' . ltrim((string)$append, '/');
    }
}

if (!function_exists('theme_url')) {
    /**
     * Theme asset URL helper (web accessible).
     * Assumes themes are served from: /themes/{activeTheme}/...
     */
    function theme_url(?string $append = ''): string
    {
        $active = (string)(Config::get('app.theme.active') ?? 'default');
        $active = trim($active);

        $root = $active === '' ? base_url('themes') : base_url('themes/' . $active);

        if ($append === '' || $append === null) {
            return $root;
        }
        return $root . '/' . ltrim((string)$append, '/');
    }
}

if (!function_exists('current_url')) {
    function current_url(): string
    {
        // Do NOT trust X-Forwarded-Host — it can be spoofed.
        $host = $_SERVER['HTTP_HOST']
            ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return (is_https() ? 'https://' : 'http://') . $host . $uri;
    }
}


if (!function_exists('base_path')) {
    function base_path(string $append = ''): string
    {

        // Normalize slashes
        $append = trim($append);
        $append = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $append);

        // Detect absolute paths:
        // - Unix: /var/www
        // - Windows drive: C:\path or C:/path
        // - Windows UNC: \\server\share
        $isAbsolute =
            str_starts_with($append, DIRECTORY_SEPARATOR) ||
            preg_match('~^[A-Za-z]:'.preg_quote(DIRECTORY_SEPARATOR, '~').'~', $append) === 1 ||
            str_starts_with($append, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);

        if ($isAbsolute) {
            return rtrim($append, DIRECTORY_SEPARATOR);
        }

        $base = rtrim(BASEPATH, DIRECTORY_SEPARATOR);

        if ($append === '') {
            return $base;
        }

        return $base . DIRECTORY_SEPARATOR . ltrim($append, DIRECTORY_SEPARATOR);
    }
}
if (!function_exists('app_path')) {
    function app_path(string $append = ''): string
    {
        // Normalize slashes
        $append = trim($append);
        $append = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $append);

        // Detect absolute paths:
        // - Unix: /var/www
        // - Windows drive: C:\path or C:/path
        // - Windows UNC: \\server\share
        $isAbsolute =
            str_starts_with($append, DIRECTORY_SEPARATOR) ||
            preg_match('~^[A-Za-z]:'.preg_quote(DIRECTORY_SEPARATOR, '~').'~', $append) === 1 ||
            str_starts_with($append, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);

        if ($isAbsolute) {
            return rtrim($append, DIRECTORY_SEPARATOR);
        }

        $base = rtrim(APPPATH, DIRECTORY_SEPARATOR);

        if ($append === '') {
            return $base;
        }

        return $base . DIRECTORY_SEPARATOR . ltrim($append, DIRECTORY_SEPARATOR);
    }
}


/* --------------------------------------------------------------------------
 | Escaping helpers
 * -------------------------------------------------------------------------- */

if (!function_exists('e')) {
    /**
     * Escape HTML text node. Null is treated as an empty string so that
     * rendering a nullable value (e.g. an optional DB column) never fatals.
     */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    /**
     * Escape HTML attribute value. Null is treated as an empty string.
     */
    function esc_attr(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    /**
     * Minimal URL escape/sanitization for output contexts.
     * (Not a validator; blocks obvious javascript: vectors.)
     */
    function esc_url(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') return '';

        // Strip control characters and embedded whitespace
        $url = preg_replace('/[\x00-\x1F\x7F\s]+/', '', $url) ?? '';
        if ($url === '') return '';

        // Block javascript: / data: (basic XSS prevention for href/src output)
        $lower = strtolower($url);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:')) {
            return '';
        }

        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/* --------------------------------------------------------------------------
 | Debug
 * -------------------------------------------------------------------------- */

if (!function_exists('dd')) {
    /**
     * Dump and die (development only).
     * In production, throws an exception instead of leaking data.
     */
    function dd(mixed ...$vars): never
    {
        $env = $_ENV['APP_ENV'] ?? (getenv('APP_ENV') ?: null);
        if ($env === null) {
            $env = Config::get('app.environment', 'production');
        }
        if ($env === 'production') {
            throw new \RuntimeException('dd() must not be used in production.');
        }
        header('Content-Type: text/plain; charset=UTF-8');
        foreach ($vars as $v) {
            var_dump($v);
        }
        exit(1);
    }
}

if (!function_exists('dt')) {
    function dt(?string $v): string { return $v ? e($v) : '-'; }
}

/* --------------------------------------------------------------------------
 | Other IMP
 * -------------------------------------------------------------------------- */

 if (!function_exists('normalize_email')) {
    function normalize_email(string $email): string
    {
        $email = trim($email);
        if ($email === '') return '';

        $email = strtolower($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
 }

if (!function_exists('pick_keys')) {
    function pick_keys(array $arr, array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $arr)) {
                $result[$key] = is_string($arr[$key])
                    ? trim($arr[$key])
                    : $arr[$key];
            }
        }
        return $result;
    }
}

if (!function_exists('path_to_url')) {
    /**
     * Convert an absolute filesystem path to a public URL.
     *
     * Requirements:
     * - $path must be under $publicRootPath (document root).
     * - $baseUrl should be your site base (e.g. site_url('') or Config::get('app.base_url')).
     *
     * Returns:
     * - Full URL on success
     * - null if $path is not under public root (cannot map)
     */
    function path_to_url(string $path, ?string $baseUrl = null, ?string $publicRootPath = null): ?string
    {
        $path = trim($path);
        if ($path === '') return null;

        $baseUrl = $baseUrl ?? (function_exists('site_url') ? site_url('') : '');
        $baseUrl = rtrim((string)$baseUrl, '/');

        // Default public root: BASEPATH (adjust if your public root is /public)
        if ($publicRootPath === null) {
            $publicRootPath = defined('BASEPATH') ? (string)BASEPATH : '';
        }
        $publicRootPath = rtrim((string)$publicRootPath, "/\\");
        if ($publicRootPath === '') return null;

        // Normalize separators for comparison
        $rootNorm = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $publicRootPath);
        $pathNorm = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        // Case-insensitive compare for Windows
        $isWindows = (DIRECTORY_SEPARATOR === '\\');
        $rootCmp = $isWindows ? strtolower($rootNorm) : $rootNorm;
        $pathCmp = $isWindows ? strtolower($pathNorm) : $pathNorm;

        $prefix = $rootCmp . DIRECTORY_SEPARATOR;
        if (!str_starts_with($pathCmp, $prefix) && $pathCmp !== $rootCmp) {
            return null; // cannot map outside docroot
        }

        // Compute web path
        $rel = ($pathCmp === $rootCmp)
            ? ''
            : substr($pathNorm, strlen($rootNorm));

        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        $rel = '/' . ltrim($rel, '/');

        return $baseUrl . $rel;
    }
}

if (!function_exists('path_is')) {
    function path_is(string $needle): bool {
        $p = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $p = rtrim($p, '/') ?: '/';
        $needle = rtrim($needle, '/') ?: '/';
        return $p === $needle;
    }
}

if (!function_exists('path_starts')) {
    function path_starts(string $prefix): bool {
        $p = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $p = rtrim($p, '/') ?: '/';
        $prefix = rtrim($prefix, '/') ?: '/';
        return str_starts_with($p, $prefix);
    }
}

if (!function_exists('cls')) {
    function cls(bool $cond, string $true, string $false = ''): string {
        return $cond ? $true : $false;
    }
}

if (!function_exists('_include')) {
    /**
     * Include a template file with isolated variable scope.
     *
     * Security: Uses EXTR_SKIP to prevent variable injection attacks.
     * Variables are accessible inside the template as $variableName.
     */
    function _include(string $filePath, array $variables = [], bool $print = true): ?string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return null;
        }

        // Use a closure to create an isolated scope with safe extract
        $render = static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP); // EXTR_SKIP prevents overwriting $__file/$__vars
            ob_start();
            include $__file;
            return (string)ob_get_clean();
        };

        $output = $render($filePath, $variables);

        if ($print) {
            print $output;
        }
        return $output;
    }
}


/* --------------------------------------------------------------------------
 | Security Helpers
 * -------------------------------------------------------------------------- */

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return \System\Security\Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        $token = csrf_token();
        return '<input type="hidden" name="_csrf" value="' . e($token) . '">';
    }
}

/* --------------------------------------------------------------------------
 | Collection Helper
 * -------------------------------------------------------------------------- */

if (!function_exists('collect')) {
    /**
     * Create a new Collection from the given items.
     *
     * Usage:
     *   collect($users)->pluck('name')->unique()->sort()->values();
     *
     * @param array|\System\Support\Collection $items
     * @return \System\Support\Collection
     */
    function collect(array|\System\Support\Collection $items = []): \System\Support\Collection
    {
        return new \System\Support\Collection($items);
    }
}

/* --------------------------------------------------------------------------
 | Route URL Generation
 * -------------------------------------------------------------------------- */

if (!function_exists('route')) {
    /**
     * Generate a URL for a named route.
     *
     * Usage:
     *   route('users.show', ['id' => 5])    → '/users/5'
     *   route('admin.dashboard')             → '/admin/dashboard'
     *
     * @param string $name Route name.
     * @param array<string, mixed> $params Parameters for {param} placeholders.
     * @param array<string, mixed> $query Optional query string parameters.
     * @return string
     */
    function route(string $name, array $params = [], array $query = []): string
    {
        return \System\Core\Routing\UrlGenerator::getInstance()->generate($name, $params, $query);
    }
}

/* --------------------------------------------------------------------------
 | Response & Navigation Helpers
 * -------------------------------------------------------------------------- */

if (!function_exists('redirect')) {
    /**
     * Create a redirect response.
     *
     * Usage:
     *   return redirect('/dashboard');
     *   return redirect('/login', 301);
     */
    function redirect(string $url, int $code = 302): \System\Http\Response
    {
        return (new \System\Http\Response())->redirect($url, $code);
    }
}

if (!function_exists('back')) {
    /**
     * Redirect to the previous page (HTTP Referer).
     *
     * Usage:
     *   return back();
     *   return back('/home');
     */
    function back(string $fallback = '/'): \System\Http\Response
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? $fallback;
        return redirect($referer);
    }
}

if (!function_exists('abort')) {
    /**
     * Throw an HTTP exception to halt execution.
     *
     * Usage:
     *   abort(404);
     *   abort(403, 'Forbidden');
     */
    function abort(int $code, string $message = ''): never
    {
        if ($message === '') {
            $message = \System\Http\HttpStatus::phrase($code);
        }

        http_response_code($code);
        throw new \System\Http\Exceptions\HttpException($code, $message);
    }
}

if (!function_exists('json')) {
    /**
     * Create a JSON response.
     *
     * Usage:
     *   return json(['success' => true]);
     *   return json(['error' => 'Not found'], 404);
     */
    function json(mixed $data, int $status = 200): \System\Http\Response
    {
        return (new \System\Http\Response())->json($data, $status);
    }
}

/* --------------------------------------------------------------------------
 | Session Helpers
 * -------------------------------------------------------------------------- */

if (!function_exists('session')) {
    /**
     * Get / set session values.
     *
     * Usage:
     *   session('user.name')               // get value
     *   session('user.name', 'John')       // set value
     *   session()                           // get SessionManager instance
     */
    function session(?string $key = null, mixed $default = null): mixed
    {
        $store = \System\Session\SessionStore::getInstance();

        if ($key === null) {
            return $store;
        }

        // If called with 2 args, it's a set
        if (func_num_args() >= 2 && $default !== null) {
            $store->set($key, $default);
            return null;
        }

        return $store->get($key, $default);
    }
}

if (!function_exists('old')) {
    /**
     * Get old form input from flash session (after redirect).
     *
     * Usage:
     *   <input name="email" value="<?= old('email') ?>">
     */
    function old(string $key, mixed $default = ''): mixed
    {
        return \System\Session\SessionStore::getFlash('_old_input.' . $key, $default);
    }
}

/* --------------------------------------------------------------------------
 | Auth Helpers
 * -------------------------------------------------------------------------- */

if (!function_exists('auth_user')) {
    /**
     * Get the currently authenticated user's session data.
     *
     * Returns null if not logged in.
     */
    function auth_user(): ?array
    {
        $auth = \System\Session\SessionStore::get('auth', null);
        if (!is_array($auth) || empty($auth)) {
            return null;
        }
        return $auth;
    }
}

if (!function_exists('auth_check')) {
    /**
     * Check if a user is currently authenticated.
     */
    function auth_check(): bool
    {
        return auth_user() !== null;
    }
}

/* --------------------------------------------------------------------------
 | Date / Time Helpers
 * -------------------------------------------------------------------------- */

if (!function_exists('now')) {
    /**
     * Get current datetime string (Y-m-d H:i:s).
     *
     * Usage:
     *   $user->created_at = now();
     */
    function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
