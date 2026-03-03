<?php
declare(strict_types=1);

namespace System\Core\Routing;

/**
 * RouteCollector
 *  - Registers routes (GET/POST/PUT/DELETE/PATCH/OPTIONS/ANY)
 *  - Supports groups by prefix (fluent group middleware)
 *  - Stores normalized route definitions for compilation
 *
 * Group usage:
 *  $r->group('/admin', function (RouteCollector $r): void {
 *      $r->get('', [DashboardController::class, 'index']);
 *  })->middleware('auth', ['roles'=>'admin','redirect'=>'/login']);
 */
final class RouteCollector
{
    /**
     * @var array<int, array{handler:mixed, middleware:array<int, array{id:string, params:array<string,string>}>}>
     */
    private array $errors = [];

    /**
     * @var array<int, array{method:string, path:string, handler:mixed, middleware:array<int, array{id:string, params:array<string,string>}>}>
     */
    private array $routes = [];

    /**
     * @var array<int, array{prefix:string, middleware:array<int, array{id:string, params:array<string,string>}>}>
     */
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
     * Returns a GroupDefinition so that fluent ->middleware() calls
     * apply to ALL registered methods, not just the last one.
     *
     * @param string[] $methods
     */
    public function match(array $methods, string $path, mixed $handler): GroupDefinition
    {
        $startIndex = count($this->routes);
        $count = 0;

        foreach ($methods as $m) {
            $m = strtoupper(trim((string)$m));
            if ($m === '') continue;
            $this->add($m, $path, $handler);
            $count++;
        }

        if ($count === 0) {
            throw new \InvalidArgumentException("No valid HTTP methods provided for match().");
        }

        $endIndex = count($this->routes);
        return new GroupDefinition($this, $startIndex, $endIndex);
    }

    /**
     * Registers route for common methods.
     */
    public function any(string $path, mixed $handler): GroupDefinition
    {
        return $this->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $path, $handler);
    }

    /* -------------------------
     * Groups (NEW fluent API only)
     * ------------------------- */

    /**
     * Group routes by prefix (fluent group middleware).
     *
     * Usage:
     * $r->group('/admin', function(RouteCollector $r){ ... })
     *   ->middleware('auth', ['roles'=>'admin']);
     */
    public function group(string $prefix, callable $callback): GroupDefinition
    {
        $parent = $this->currentGroup();

        $prefix = $this->normalizePrefix($prefix);

        $startRouteIndex = count($this->routes);
        $errorsBefore    = array_keys($this->errors);

        // Push new group scope (inherits parent's middleware; group middleware applied fluently later)
        $this->groupStack[] = [
            'prefix'     => $this->joinPaths($parent['prefix'], $prefix),
            'middleware' => $parent['middleware'],
        ];

        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
        }

        $endRouteIndex = count($this->routes);
        $errorsAfter   = array_keys($this->errors);
        $newErrorCodes = array_values(array_diff($errorsAfter, $errorsBefore));

        return new GroupDefinition($this, $startRouteIndex, $endRouteIndex, $newErrorCodes);
    }

    /* -------------------------
     * GroupDefinition helpers
     * ------------------------- */

    /**
     * Build normalized middleware list from fluent middleware() call.
     *
     * @return array<int, array{id:string, params:array<string,string>}>
     */
    public function _buildMiddlewareAdd(array|string $mw, array $params = []): array
    {
        $add = [];

        // middleware('auth', ['roles'=>...])
        if (is_string($mw)) {
            $id = trim($mw);
            if ($id !== '') {
                $add[] = ['id' => $id, 'params' => $params];
            }
            return $this->normalizeMiddlewareList($add);
        }

        // middleware(['auth','limiter'])
        foreach ($mw as $v) {
            $id = trim((string)$v);
            if ($id === '') continue;
            $add[] = ['id' => $id, 'params' => []];
        }

        return $this->normalizeMiddlewareList($add);
    }

    /**
     * Apply middleware to routes in [start, end) range.
     *
     * @param array<int, array{id:string, params:array<string,string>}> $add
     */
    public function _applyMiddlewareToRouteRange(int $start, int $end, array $add): void
    {
        $start = max(0, $start);
        $end   = min($end, count($this->routes));

        for ($i = $start; $i < $end; $i++) {
            $current = $this->routes[$i]['middleware'] ?? [];
            if (!is_array($current)) $current = [];

            $this->routes[$i]['middleware'] = $this->mergeMiddleware($current, $add);
        }
    }

    /**
     * Apply middleware to error handlers registered within the group callback.
     *
     * @param int[] $codes
     * @param array<int, array{id:string, params:array<string,string>}> $add
     */
    public function _applyMiddlewareToErrors(array $codes, array $add): void
    {
        foreach ($codes as $code) {
            if (!isset($this->errors[$code])) continue;

            $current = $this->errors[$code]['middleware'] ?? [];
            if (!is_array($current)) $current = [];

            $this->errors[$code]['middleware'] = $this->mergeMiddleware($current, $add);
        }
    }

    /**
     * Internal bridge to reuse the same merge semantics from RouteDefinition.
     *
     * @param array<int,mixed> $parent
     * @param array<int,mixed> $child
     * @return array<int, array{id:string, params:array<string,string>}>
     */
    public function _mergeMiddlewareLists(array $parent, array $child): array
    {
        return $this->mergeMiddleware($parent, $child);
    }

    /* -------------------------
     * Resource Routes (C1)
     * ------------------------- */

    /**
     * Register a full resource route (7 routes).
     *
     * Generates:
     *   GET    /{name}           → index    ({name}.index)
     *   GET    /{name}/create    → create   ({name}.create)
     *   POST   /{name}           → store    ({name}.store)
     *   GET    /{name}/{id}      → show     ({name}.show)
     *   GET    /{name}/{id}/edit → edit     ({name}.edit)
     *   PUT    /{name}/{id}      → update   ({name}.update)
     *   DELETE /{name}/{id}      → destroy  ({name}.destroy)
     *
     * @param string $name        Resource name (URI segment), e.g. 'users', 'posts'
     * @param string $controller  FQCN of the controller
     * @param array  $options     Optional: 'only' => [...], 'except' => [...], 'parameter' => 'user'
     * @return ResourceDefinition Fluent object supporting ->only(), ->except(), ->middleware()
     */
    public function resource(string $name, string $controller, array $options = []): ResourceDefinition
    {
        $name      = trim($name, '/');
        $parameter = $options['parameter'] ?? $this->singularize($name);

        $allActions = [
            'index'   => ['GET',    "/{$name}",                       'index'],
            'create'  => ['GET',    "/{$name}/create",                'create'],
            'store'   => ['POST',   "/{$name}",                       'store'],
            'show'    => ['GET',    "/{$name}/{{$parameter}}",        'show'],
            'edit'    => ['GET',    "/{$name}/{{$parameter}}/edit",   'edit'],
            'update'  => ['PUT',    "/{$name}/{{$parameter}}",        'update'],
            'destroy' => ['DELETE', "/{$name}/{{$parameter}}",        'destroy'],
        ];

        // Filter by only/except
        $actions = $this->filterResourceActions($allActions, $options);

        $startIndex = count($this->routes);

        foreach ($actions as $action => $spec) {
            [$method, $path, $controllerMethod] = $spec;
            $this->add($method, $path, [$controller, $controllerMethod])
                 ->name("{$name}.{$action}");
        }

        $endIndex = count($this->routes);

        return new ResourceDefinition($this, $startIndex, $endIndex, $name, $controller, $parameter, $allActions);
    }

    /**
     * Register an API resource route (5 routes, no create/edit forms).
     *
     * Generates: index, store, show, update, destroy
     */
    public function apiResource(string $name, string $controller, array $options = []): ResourceDefinition
    {
        $options['except'] = array_merge($options['except'] ?? [], ['create', 'edit']);
        return $this->resource($name, $controller, $options);
    }

    /**
     * Filter resource actions by only/except options.
     *
     * @param array<string, array> $allActions
     * @param array<string, mixed> $options
     * @return array<string, array>
     */
    private function filterResourceActions(array $allActions, array $options): array
    {
        if (isset($options['only'])) {
            $only = (array)$options['only'];
            return array_intersect_key($allActions, array_flip($only));
        }

        if (isset($options['except'])) {
            $except = (array)$options['except'];
            return array_diff_key($allActions, array_flip($except));
        }

        return $allActions;
    }

    /**
     * Simple singularize: removes trailing 's' from resource name.
     * E.g., 'users' → 'user', 'posts' → 'post'
     * Falls back to the name itself if it doesn't end with 's'.
     */
    private function singularize(string $name): string
    {
        // Handle common plural patterns
        if (str_ends_with($name, 'ies')) {
            return substr($name, 0, -3) . 'y';    // 'categories' → 'category'
        }
        if (str_ends_with($name, 'ses') || str_ends_with($name, 'xes') || str_ends_with($name, 'zes')) {
            return substr($name, 0, -2);           // 'addresses' → 'address'
        }
        if (str_ends_with($name, 's') && !str_ends_with($name, 'ss')) {
            return substr($name, 0, -1);           // 'users' → 'user'
        }
        return $name;
    }

    /* -------------------------
     * Reading collected routes
     * ------------------------- */

    /**
     * @return array<int, array{method:string, path:string, handler:mixed, middleware:array<int, array{id:string, params:array<string,string>}>}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function clear(): void
    {
        $this->routes = [];
        $this->errors = [];
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

        $group    = $this->currentGroup();
        $fullPath = $this->joinPaths($group['prefix'], $path);

        $route = [
            'method'     => $method,
            'path'       => $fullPath,
            'handler'    => $handler,
            'middleware' => $group['middleware'],
            'name'       => '',
        ];

        $idx = count($this->routes);
        $this->routes[] = $route;

        return new RouteDefinition($this, $idx);
    }

    /**
     * @return array{prefix:string, middleware:array<int, array{id:string, params:array<string,string>}>}
     */
    private function currentGroup(): array
    {
        return $this->groupStack[count($this->groupStack) - 1];
    }

    /* -------------------------
     * Errors
     * ------------------------- */

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
     * @return array<int, array{handler:mixed, middleware:array<int, array{id:string, params:array<string,string>}>}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /* -------------------------
     * Internal mutators used by RouteDefinition
     * ------------------------- */

    /**
     * @param array<int, mixed> $middleware
     */
    public function _setMiddleware(int $index, array $middleware): void
    {
        $this->routes[$index]['middleware'] = $this->normalizeMiddlewareList($middleware);
    }

    /**
     * Set a name on a specific route.
     */
    public function _setName(int $index, string $name): void
    {
        $name = trim($name);
        if ($name !== '' && isset($this->routes[$index])) {
            $this->routes[$index]['name'] = $name;
        }
    }

    /**
     * Apply a name prefix to all routes in [start, end) range.
     * Only routes that already have a name will be prefixed.
     */
    public function _applyNamePrefixToRouteRange(int $start, int $end, string $prefix): void
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return;
        }

        $start = max(0, $start);
        $end   = min($end, count($this->routes));

        for ($i = $start; $i < $end; $i++) {
            $currentName = $this->routes[$i]['name'] ?? '';
            if ($currentName !== '') {
                $this->routes[$i]['name'] = $prefix . $currentName;
            }
        }
    }

    /* -------------------------
     * Middleware utils
     * ------------------------- */

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
                    continue;
                }

                $out[$k] = trim((string)$v);
            }
            ksort($out);
            return $out;
        };

        $out  = [];
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

        $out  = [];
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
        if ($path === '') return '/';

        if ($path[0] !== '/') $path = '/' . $path;

        // remove trailing slash (except root)
        if (strlen($path) > 1) $path = rtrim($path, '/');

        return $path;
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '' || $prefix === '/') return '';

        if ($prefix[0] !== '/') $prefix = '/' . $prefix;

        return rtrim($prefix, '/');
    }

    private function joinPaths(string $a, string $b): string
    {
        if ($a === '') return $this->normalizePath($b);
        if ($b === '' || $b === '/') return $this->normalizePath($a);

        return $this->normalizePath($a . '/' . ltrim($b, '/'));
    }
}
