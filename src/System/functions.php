<?php
declare(strict_types=1);

use System\Config;
use System\Hooks;

/*
 * Optional procedural helpers.
 *
 * Notes:
 * - Core framework classes should not depend on these functions.
 * - Load this file in your application bootstrap if you want the helpers.
 */

function out(string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
}

function okFile(string $file): bool {
    return is_file($file) && is_readable($file);
}

function add_action(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1): void
{
    Hooks::add_action($hook, $callback, $priority, $name, $accepted_args);
}

function do_action(string $hook, ...$args): void
{
    Hooks::do_action($hook, ...$args);
}

function add_filter(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1): void
{
    Hooks::add_filter($hook, $callback, $priority, $name, $accepted_args);
}

function apply_filter(string $hook, $value, ...$args)
{
    return Hooks::apply_filter($hook, $value, ...$args);
}

function site_url(string $append = ""): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

    // Framework default: empty base. Applications can set Config::set('app.site', '/subdir')
    $base = rtrim((string) (Config::get('app.site') ?? ''), '/');

    $url = rtrim($protocol . $host . '/' . ltrim($base, '/'), '/');

    if ($append === '' || $append === null) {
        return $url;
    }

    return $url . '/' . ltrim($append, '/');
}

function get_current_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    $protocol = $isHttps ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    return $protocol . $host . $requestUri;
}
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}