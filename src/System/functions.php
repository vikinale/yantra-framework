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
     * Get config value (or set if $value is provided).
     *
     * Usage:
     *   config('app.url')
     *   config('app.url', 'https://example.com')
     */
    function config(string $key, mixed $value = null): mixed
    {
        if (func_num_args() === 2) {
            // If your Config class doesn't support set(), remove this branch.
            if (method_exists(Config::class, 'set')) {
                Config::set($key, $value);
                return $value;
            }
        }
        return Config::get($key);
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
        $lower = strtolower(trim((string)$val));
        return match ($lower) {
            'true', '(true)', '1', 'yes', 'on'  => true,
            'false','(false)','0', 'no',  'off' => false,
            'null', '(null)'                   => null,
            default                            => $val,
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

// if (!function_exists('base_path')) {
//     function base_path(string $append = ''): string
//     {
//         $base = defined('BASEPATH') ? (string)BASEPATH : dirname(__DIR__);
//         $base = rtrim($base, DIRECTORY_SEPARATOR);

//         return $append === ''
//             ? $base
//             : $base . DIRECTORY_SEPARATOR . ltrim($append, DIRECTORY_SEPARATOR);
//     }
// }

if (!function_exists('app_path')) {
    function app_path(string $append = ''): string
    {
        $app = defined('APPPATH') ? (string)APPPATH : base_path('app');
        $app = rtrim($app, DIRECTORY_SEPARATOR);

        return $append === ''
            ? $app
            : $app . DIRECTORY_SEPARATOR . ltrim($append, DIRECTORY_SEPARATOR);
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
        $host = $_SERVER['HTTP_X_FORWARDED_HOST']
            ?? $_SERVER['HTTP_HOST']
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

if (!function_exists('asset_url')) {
    /**
     * Public asset URL helper.
     * Default base: /assets
     * Override with Config('app.assets.base') e.g. '/public' or '/assets'
     */
    function asset_url(?string $append = ''): string
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
        $active = (string)(Config::get('app.theme.active') ?? '');
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
        $host = $_SERVER['HTTP_X_FORWARDED_HOST']
            ?? $_SERVER['HTTP_HOST']
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
     * Escape HTML text node.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    /**
     * Escape HTML attribute value.
     */
    function esc_attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    /**
     * Minimal URL escape/sanitization for output contexts.
     * (Not a validator; blocks obvious javascript: vectors.)
     */
    function esc_url(string $url): string
    {
        $url = trim($url);
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
     * Dump and die (development).
     */
    function dd(mixed ...$vars): never
    {
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