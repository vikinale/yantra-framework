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
     * Normalize a single middleware token.
     *
     * Supported:
     *  - "auth"
     *  - "auth:admin"
     *  - "throttle:60,1"
     *  - "role:admin, editor"  => "role:admin,editor"
     */
    private function normalizeMiddlewareToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        // split ONLY on first ":" (allow future params to contain ":" if needed)
        $pos = strpos($token, ':');
        if ($pos === false) {
            return $token; // plain alias
        }

        $alias = trim(substr($token, 0, $pos));
        if ($alias === '') {
            return '';
        }

        $paramsRaw = trim(substr($token, $pos + 1));
        if ($paramsRaw === '') {
            return $alias; // "auth:" -> "auth"
        }

        $parts = array_map('trim', explode(',', $paramsRaw));
        $parts = array_values(array_filter($parts, static fn(string $p) => $p !== ''));

        return $parts ? ($alias . ':' . implode(',', $parts)) : $alias;
    }

/**
 * @param array<int,mixed> $mw
 * @return array<int, array{id:string, params:array<string,string>}>
 */
private function normalizeMiddlewareList(array $mw): array
{
    $normalizeParams = static function (array $p): array {
        $out = [];
        foreach ($p as $k => $v) {
            $k = trim((string)$k);
            if ($k === '') continue;

            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            } elseif ($v === null) {
                $v = '';
            } elseif (is_scalar($v)) {
                $v = (string)$v;
            } else {
                continue; // ignore arrays/objects
            }

            $out[$k] = trim((string)$v);
        }
        ksort($out);
        return $out;
    };

    $out = [];
    $seen = [];

    foreach ($mw as $item) {
        // Legacy string middleware: "auth"
        if (is_string($item)) {
            $id = trim($item);
            if ($id === '') continue;

            $key = $id . '|';
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $out[] = ['id' => $id, 'params' => []];
            continue;
        }

        // Structured middleware: ['id'=>'auth','params'=>[...]]
        if (is_array($item)) {
            $id = trim((string)($item['id'] ?? ''));
            if ($id === '') continue;

            $p = $item['params'] ?? [];
            if (!is_array($p)) $p = [];
            $p = $normalizeParams($p);

            $key = $id . '|' . http_build_query($p);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $out[] = ['id' => $id, 'params' => $p];
        }
    }

    return $out;
}



/**
 * Keep parent order, then append new ones (deduped).
 *
 * Accepts:
 *  - legacy string middleware: "auth"
 *  - structured middleware: ['id'=>'auth','params'=>[...] ]
 *
 * @param array<int,mixed> $parent
 * @param array<int,mixed> $child
 * @return array<int, array{id:string, params:array<string,string>}>
 */
private function mergeMiddleware(array $parent, array $child): array
{
    $normalizeParams = static function (array $p): array {
        $out = [];
        foreach ($p as $k => $v) {
            $k = trim((string)$k);
            if ($k === '') continue;

            if (is_bool($v)) $v = $v ? '1' : '0';
            elseif ($v === null) $v = '';
            elseif (is_scalar($v)) $v = (string)$v;
            else continue;

            $out[$k] = trim((string)$v);
        }
        ksort($out);
        return $out;
    };

    $out = [];
    $seen = [];

    foreach ([$parent, $child] as $list) {
        foreach ($list as $item) {
            // legacy string
            if (is_string($item)) {
                $id = trim($item);
                if ($id === '') continue;

                $key = $id . '|';
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $out[] = ['id' => $id, 'params' => []];
                continue;
            }

            // structured
            if (is_array($item)) {
                $id = trim((string)($item['id'] ?? ''));
                if ($id === '') continue;

                $p = $item['params'] ?? [];
                if (!is_array($p)) $p = [];
                $p = $normalizeParams($p);

                $key = $id . '|' . http_build_query($p);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $out[] = ['id' => $id, 'params' => $p];
            }
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
     * Usage:
     *  ->middleware('auth')
     *  ->middleware('auth', ['roles'=>'admin','redirect'=>'/login'])
     *  ->middleware(['auth','limiter']) // still allowed
     */
    public function middleware(array|string $mw, array $params = []): self
    {
        $routes  = $this->collector->getRoutes();
        $current = $routes[$this->index]['middleware'] ?? [];
        if (!is_array($current)) {
            $current = [];
        }

        $add = [];

        // Case A: middleware('auth', ['roles'=>...])
        if (is_string($mw)) {
            $id = trim($mw);
            if ($id !== '') {
                $add[] = [
                    'id'     => $id,
                    'params' => $params,
                ];
            }
        }
        // Case B: middleware(['auth','limiter'])
        else {
            foreach ($mw as $v) {
                $id = trim((string)$v);
                if ($id === '') continue;

                $add[] = [
                    'id'     => $id,
                    'params' => [], // per-item params not provided in this form
                ];
            }
        }

        // Normalize + append with de-dup based on (id + normalized params)
        $normalizeParams = static function (array $p): array {
            // keep only scalar-ish values; stringify scalars; ignore nested arrays/objects
            $out = [];
            foreach ($p as $k => $v) {
                $k = trim((string)$k);
                if ($k === '') continue;

                if (is_bool($v)) $v = $v ? '1' : '0';
                elseif ($v === null) $v = '';
                elseif (is_scalar($v)) $v = (string)$v;
                else continue;

                $out[$k] = trim($v);
            }
            ksort($out); // canonical order for de-dup key stability
            return $out;
        };

        $seen = [];
        $merged = [];

        foreach (array_merge($current, $add) as $item) {
            // Allow legacy strings already present
            if (is_string($item)) {
                $id = trim($item);
                if ($id === '') continue;

                $key = $id . '|'; // no params
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $merged[] = ['id' => $id, 'params' => []];
                continue;
            }

            if (!is_array($item)) continue;

            $id = trim((string)($item['id'] ?? ''));
            if ($id === '') continue;

            $p = $item['params'] ?? [];
            if (!is_array($p)) $p = [];
            $p = $normalizeParams($p);

            $key = $id . '|' . http_build_query($p);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $merged[] = ['id' => $id, 'params' => $p];
        }

        $this->collector->_setMiddleware($this->index, $merged);
        return $this;
    }

}
