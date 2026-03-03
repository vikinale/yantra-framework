<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;
use System\Config;
use System\Core\Routing\RouteCollector;
use RuntimeException;

/**
 * RouteListCommand — Display a table of all registered routes.
 *
 * Essential debugging tool: shows all routes, their methods, names,
 * handlers, and middleware at a glance.
 *
 * Usage:
 *   php bin/yantra route:list
 *   php bin/yantra route:list --method=GET
 *   php bin/yantra route:list --name=users
 */
final class RouteListCommand extends AbstractCommand
{
    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'List all registered routes with their methods, names, handlers, and middleware.';
    }

    public function usage(): array
    {
        return [
            'yantra route:list',
            'yantra route:list --method=GET',
            'yantra route:list --name=admin',
            'yantra route:list --path=/users',
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $base  = (string) ($in->option('base', getcwd() ?: '.'));
        $app   = (string) ($in->option('app', 'app'));

        $base    = rtrim($base, "/\\");
        $appPath = $base . DIRECTORY_SEPARATOR . trim($app, "/\\");

        // Filters
        $filterMethod = strtoupper(trim((string) ($in->option('method', ''))));
        $filterName   = trim((string) ($in->option('name', '')));
        $filterPath   = trim((string) ($in->option('path', '')));

        // Configure paths
        Config::setAppPath($appPath);

        $routesFile = $appPath . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'routes.php';
        if (!is_file($routesFile)) {
            throw new RuntimeException("Routes file not found: {$routesFile}");
        }

        $routesDef = require $routesFile;
        if (!is_callable($routesDef)) {
            throw new RuntimeException("routes.php must return callable(RouteCollector): void");
        }

        // Collect routes
        $collector = new RouteCollector();
        $routesDef($collector);

        $routes = $collector->getRoutes();

        if (empty($routes)) {
            $out->writeln(Style::warn('No routes registered.'));
            return 0;
        }

        // Build table rows
        $rows = [];
        foreach ($routes as $route) {
            $method  = strtoupper($route['method'] ?? '');
            $path    = $route['path'] ?? '';
            $name    = $route['name'] ?? '';
            $handler = $this->formatHandler($route['handler'] ?? '');
            $mw      = $this->formatMiddleware($route['middleware'] ?? []);

            // Apply filters
            if ($filterMethod !== '' && $method !== $filterMethod) {
                continue;
            }
            if ($filterName !== '' && stripos($name, $filterName) === false) {
                continue;
            }
            if ($filterPath !== '' && stripos($path, $filterPath) === false) {
                continue;
            }

            $rows[] = [$method, $path, $name, $handler, $mw];
        }

        if (empty($rows)) {
            $out->writeln(Style::warn('No routes match the given filters.'));
            return 0;
        }

        // Calculate column widths
        $headers = ['Method', 'URI', 'Name', 'Handler', 'Middleware'];
        $widths  = array_map('strlen', $headers);

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], strlen($cell));
            }
        }

        // Render table
        $separator = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), $widths)) . '+';

        $out->writeln('');
        $out->writeln($separator);
        $out->writeln($this->formatRow($headers, $widths));
        $out->writeln($separator);

        foreach ($rows as $row) {
            $out->writeln($this->formatRow($row, $widths));
        }

        $out->writeln($separator);
        $out->writeln('');
        $out->writeln(Style::ok('Showing ' . count($rows) . ' route(s).'));

        return 0;
    }

    private function formatRow(array $cells, array $widths): string
    {
        $parts = [];
        foreach ($cells as $i => $cell) {
            $parts[] = ' ' . str_pad($cell, $widths[$i]) . ' ';
        }
        return '|' . implode('|', $parts) . '|';
    }

    private function formatHandler(mixed $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2) {
            $class  = is_string($handler[0]) ? $handler[0] : get_class($handler[0]);
            $method = $handler[1] ?? '__invoke';

            // Shorten FQCN for readability
            $short = str_contains($class, '\\')
                ? substr($class, strrpos($class, '\\') + 1)
                : $class;

            return $short . '@' . $method;
        }

        if ($handler instanceof \Closure) {
            return 'Closure';
        }

        return (string) $handler;
    }

    /**
     * @param array<int, array{id:string, params:array}> $middleware
     */
    private function formatMiddleware(array $middleware): string
    {
        if (empty($middleware)) {
            return '';
        }

        $names = [];
        foreach ($middleware as $mw) {
            if (is_string($mw)) {
                $names[] = $mw;
            } elseif (is_array($mw) && isset($mw['id'])) {
                $names[] = $mw['id'];
            }
        }

        return implode(', ', $names);
    }
}
