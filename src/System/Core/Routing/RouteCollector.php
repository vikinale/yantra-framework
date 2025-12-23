<?php
declare(strict_types=1);

namespace System\Core\Routing;

/**
 * RouteCollector
 *  - Registers routes (GET/POST/PUT/DELETE/PATCH/OPTIONS/ANY)
 *  - Supports groups: prefix, middleware
 *  - Stores normalized route definitions for compilation
 */
final class RouteCollector
{
    /** @var array<int, array{handler:mixed, middleware:array<int,string>}> */
    private array $errors = [];
    
    /** @var array<int, array{method:string,path:string,handler:mixed,middleware:array<int,string>}> */
    private array $routes = [];

    /** @var array<int, array{prefix:string, middleware:array<int,string>}> */
    private array $groupStack = [];

    public function __construct()
    {
        // Base group (root)
        $this->groupStack[] = [
            'prefix'     => '',
            'middleware' => [],
        ];
    }

 
    /* -------------------------
     * Public API: HTTP methods
     * ------------------------- */

    public function get(string $path, mixed $handler): RouteDefinition
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): RouteDefinition
    {
        return $this->add('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): RouteDefinition
    {
        return $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, mixed $handler): RouteDefinition
    {
        return $this->add('PATCH', $path, $handler);
    }
 
    public function delete(string $path, mixed $handler): RouteDefinition
    {
        return $this->add('DELETE', $path, $handler);
    }
 
    public function options(string $path, mixed $handler): RouteDefinition
    {
        return $this->add('OPTIONS', $path, $handler);
    }

    /**
     * Registers same route for multiple methods.
     *
     * @param string[] $methods
     */
    public function match(array $methods, string $path, mixed $handler): RouteDefinition
    {
        $def = null;

        foreach ($methods as $m) {
            $m = strtoupper(trim((string)$m));
            if ($m === '') {
                continue;
            }
            $def = $this->add($m, $path, $handler);
        }

        if ($def === null) {
            throw new \InvalidArgumentException("No valid HTTP methods provided for match().");
        }

        return $def;
    }

    /**
     * Registers route for common methods.
     */
    public function any(string $path, mixed $handler): RouteDefinition
    {
        return $this->match(['GET','POST','PUT','PATCH','DELETE','OPTIONS'], $path, $handler);
    }

    /* -------------------------
     * Groups
     * ------------------------- */

    /**
     * Group routes with options:
     *  - prefix: string
     *  - middleware: string|string[]
     *
     * Example:
     * $r->group(['prefix'=>'/admin','middleware'=>['auth']], function($r){ ... });
     */
    public function group(array $opts, callable $callback): void
    {
        $parent = $this->currentGroup();

        $prefix = (string)($opts['prefix'] ?? '');
        $prefix = $this->normalizePrefix($prefix);

        $mw = $opts['middleware'] ?? [];
        if (is_string($mw)) {
            $mw = [$mw];
        }
        if (!is_array($mw)) {
            $mw = [];
        }

        $mw = $this->normalizeMiddlewareList($mw);

        $this->groupStack[] = [
            'prefix'     => $this->joinPaths($parent['prefix'], $prefix),
            'middleware' => $this->mergeMiddleware($parent['middleware'], $mw),
        ];

        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /* -------------------------
     * Reading collected routes
     * ------------------------- */

    /**
     * @return array<int, array{method:string,path:string,handler:mixed,middleware:array<int,string>}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function clear(): void
    {
        $this->routes = [];
        $this->groupStack = [[
            'prefix'     => '',
            'middleware' => [],
        ]];
    }

    /* -------------------------
     * Core add route
     * ------------------------- */

    private function add(string $method, string $path, mixed $handler): RouteDefinition
    {
        $method = strtoupper(trim($method));
        if ($method === '') {
            throw new \InvalidArgumentException("HTTP method cannot be empty.");
        }

        $path = $this->normalizePath($path);

        $group = $this->currentGroup();
        $fullPath = $this->joinPaths($group['prefix'], $path);

        $route = [
            'method'     => $method,
            'path'       => $fullPath,
            'handler'    => $handler,
            'middleware' => $group['middleware'],
        ];

        $idx = count($this->routes);
        $this->routes[] = $route;

        return new RouteDefinition($this, $idx);
    }

    private function currentGroup(): array
    {
        return $this->groupStack[count($this->groupStack) - 1];
    }

    
    /**
     * Register an error handler (e.g. 404, 405, 500).
     *
     * Handler format: [ControllerClass, method]
     * Middleware applies (current group middleware will be attached)
     */
    public function error(int $code, mixed $handler): void
    {
        if ($code < 400 || $code > 599) {
            throw new \InvalidArgumentException("Error code must be 400-599, got {$code}.");
        }

        $group = $this->currentGroup();
        $this->errors[$code] = [
            'handler'    => $handler,
            'middleware' => $group['middleware'] ?? [],
        ];
    }
    
    /**
     * @return array<int, array{handler:mixed, middleware:array<int,string>}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /* -------------------------
     * Internal mutators used by RouteDefinition
     * ------------------------- */

    public function _setMiddleware(int $index, array $middleware): void
    {
        $this->routes[$index]['middleware'] = $this->normalizeMiddlewareList($middleware);
    }

    /* -------------------------
     * Middleware utils
     * ------------------------- */

    /**
     * @param array<int,mixed> $mw
     * @return array<int,string>
     */
    private function normalizeMiddlewareList(array $mw): array
    {
        $mw = array_map('strval', $mw);
        $mw = array_values(array_filter($mw, static fn(string $v) => trim($v) !== ''));
        // keep order, remove duplicates
        $out = [];
        $seen = [];
        foreach ($mw as $m) {
            $m = trim($m);
            if ($m === '' || isset($seen[$m])) {
                continue;
            }
            $seen[$m] = true;
            $out[] = $m;
        }
        return $out;
    }

    /**
     * Keep parent order, then append new ones (deduped).
     *
     * @param array<int,string> $parent
     * @param array<int,string> $child
     * @return array<int,string>
     */
    private function mergeMiddleware(array $parent, array $child): array
    {
        $out = [];
        $seen = [];

        foreach ([$parent, $child] as $list) {
            foreach ($list as $m) {
                $m = trim((string)$m);
                if ($m === '' || isset($seen[$m])) {
                    continue;
                }
                $seen[$m] = true;
                $out[] = $m;
            }
        }

        return $out;
    }

    /* -------------------------
     * Path utils
     * ------------------------- */

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        // remove trailing slash (except root)
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '' || $prefix === '/') {
            return '';
        }
        if ($prefix[0] !== '/') {
            $prefix = '/' . $prefix;
        }
        return rtrim($prefix, '/');
    }

    private function joinPaths(string $a, string $b): string
    {
        if ($a === '') {
            return $this->normalizePath($b);
        }
        if ($b === '' || $b === '/') {
            return $this->normalizePath($a);
        }
        return $this->normalizePath($a . '/' . ltrim($b, '/'));
    }
}

/**
 * RouteDefinition (fluent config for the last added route)
 *  - ->middleware([...])
 */
final class RouteDefinition
{
    public function __construct(
        private RouteCollector $collector,
        private int $index
    ) {}

    /**
     * @param string|string[] $mw
     */
    public function middleware(array|string $mw): self
    {
        if (is_string($mw)) {
            $mw = [$mw];
        }
        if (!is_array($mw)) {
            $mw = [];
        }

        $mw = array_values(array_filter(array_map('strval', $mw), static fn($v) => trim((string)$v) !== ''));

        $routes = $this->collector->getRoutes();
        $current = $routes[$this->index]['middleware'] ?? [];

        // append while preserving order + dedupe
        $seen = [];
        $merged = [];

        foreach (array_merge($current, $mw) as $m) {
            $m = trim((string)$m);
            if ($m === '' || isset($seen[$m])) {
                continue;
            }
            $seen[$m] = true;
            $merged[] = $m;
        }

        $this->collector->_setMiddleware($this->index, $merged);
        return $this;
    }
}
