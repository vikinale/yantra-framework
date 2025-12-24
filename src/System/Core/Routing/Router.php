<?php
declare(strict_types=1);

namespace System\Core\Routing;

use System\Http\Request;
use System\Http\Response;

/**
 * Router (per-method cached)
 *
 * Cache layout:
 *  - {cacheDir}/GET.php       => ['static'=>..., 'dynamic'=>...]
 *  - {cacheDir}/POST.php      => ['static'=>..., 'dynamic'=>...]
 *  - {cacheDir}/__index.php   => ['p:<sha1(path)>' => ['GET'=>true,'POST'=>true]]
 */
final class Router
{
    private string $cacheDir;

    /** @var array{static: array<string,array>, dynamic: array<int,array>} */
    private array $bucket = ['static' => [], 'dynamic' => []];

    /** @var array<int, array{handler:mixed, middleware:array<int,mixed>}> */
    private array $errors = [];

    /** @var array<int,array{from:string,to:string,status:int}> */
    private array $redirects = [];

    /** @var array<string,array<string,bool>>|null */
    private ?array $index = null;

    private bool $loaded = false;

    private bool $customRoutes = false;

    /**
     * Resolves middleware identifiers into callables.
     *
     * Signature requirement for resolved middleware callable:
     *   function(Request $req, Response $res, callable $next, array $params): void
     *
     * @var null|callable(string):callable
     */
    private $middlewareResolver = null;
    private bool $middlewareEnabled = false;

    public function __construct(string $cacheDir, ?callable $middlewareResolver = null)
    {
        $this->middlewareResolver = $middlewareResolver;
        if ($cacheDir !== '') {
            $this->setCacheDir($cacheDir);
        }
    }

    public function setCacheDir(string $cacheDir): void
    {
        $cacheDir = rtrim($cacheDir, '/\\');
        if ($cacheDir === '') {
            throw new \InvalidArgumentException('Cache dir cannot be empty.');
        }
        $this->cacheDir = $cacheDir;
    }

    public function enableCustomRoutes(bool $enabled = true): void
    {
        $this->customRoutes = $enabled;
    }

    public function enableMiddleware(bool $enabled = true): void
    {
        $this->middlewareEnabled = $enabled;
    }

    public function setMiddlewareResolver(callable $resolver): void
    {
        $this->middlewareResolver = $resolver;
    }

    /* -------------------------------------------------------------------------
     * Loading strategies
     * ------------------------------------------------------------------------- */

    /**
     * Production: load only the method bucket from cache dir.
     * Index is loaded lazily (only if needed for 405).
     */
    public function loadFromCacheDir(string $method): void
    {
        $method = strtoupper(trim($method));
        if ($method === '') {
            $method = 'GET';
        }

        $methodFile = $this->cacheDir . DIRECTORY_SEPARATOR . $method . '.php';

        if (!is_file($methodFile) || !is_readable($methodFile)) {
            throw new \RuntimeException("Route cache missing for method {$method}: {$methodFile}");
        }

        $bucket = require $methodFile;
        if (!is_array($bucket) || !isset($bucket['static'], $bucket['dynamic'])) {
            throw new \RuntimeException("Invalid route cache structure in {$methodFile}");
        }

        $this->bucket = [
            'static'  => is_array($bucket['static']) ? $bucket['static'] : [],
            'dynamic' => is_array($bucket['dynamic']) ? $bucket['dynamic'] : [],
        ];

        $this->loaded = true;
        $this->index = null; // lazy
    }

    private function loadErrorsIfNeeded(): void
    {
        if ($this->errors !== []) {
            return;
        }

        $errorsFile = $this->cacheDir . DIRECTORY_SEPARATOR . '__errors.php';
        if (!is_file($errorsFile) || !is_readable($errorsFile)) {
            $this->errors = [];
            return;
        }

        $e = require $errorsFile;
        $this->errors = is_array($e) ? $e : [];
    }

    private function dispatchError(int $code, Request $req, Response $res): void
    {
        $this->loadErrorsIfNeeded();

        if (!isset($this->errors[$code])) {
            $res->withStatus($code);
            return;
        }


        $def = $this->errors[$code];
        // Run middleware + handler
        $mw = $def['middleware'] ?? [];
        if (!is_array($mw)) {
            $mw = [];
        }

        // If you have runRoute() from earlier middleware-enabled Router:
        $this->runRoute(
            [
                'handler'    => $def['handler'] ?? null,
                'middleware' => $mw,
            ],
            $req,
            $res,
            ['code' => (string)$code]
        );
    }

    /**
     * Development: compile routes using RouteCollector + RouteCompiler and write per-method caches.
     *
     * @param callable(RouteCollector):void $routesDefinition
     * @param bool $force If true, always rebuild cache
     */
    public function compileAndCache(callable $routesDefinition, bool $force = false): void
    {
        $cacheIndexFile = $this->cacheDir . DIRECTORY_SEPARATOR . '__index.php';

        // If not forcing and index exists, assume cache exists
        if (!$force && is_file($cacheIndexFile)) {
            return;
        }

        $collector = new RouteCollector();
        $routesDefinition($collector);

        $compiler = new RouteCompiler();
        $compiler->compileToMethodCacheDir($collector->getRoutes(), $this->cacheDir, $collector->getErrors());
    }

    /**
     * Optional direct injection (useful for tests or custom bootstrap).
     *
     * @param array{static: array<string,array>, dynamic: array<int,array>} $bucket
     * @param array<string,array<string,bool>> $index
     */
    public function setRoutesBucket(array $bucket, array $index = []): void
    {
        $this->bucket = [
            'static'  => is_array($bucket['static'] ?? null) ? $bucket['static'] : [],
            'dynamic' => is_array($bucket['dynamic'] ?? null) ? $bucket['dynamic'] : [],
        ];
        $this->index = $index;
        $this->loaded = true;
    }

    private function applyRedirects(Request $req, Response $res): bool
    {
        if ($this->redirects === []) {
            return false;
        }

        $path = $this->normalize($req->getPath());

        foreach ($this->redirects as $rule) {
            $from   = $rule['from'] ?? '';
            $to     = $rule['to'] ?? '';
            $status = (int)($rule['status'] ?? 301);

            if ($from === '' || $to === '') {
                continue;
            }

            // Regex rule
            if ($from[0] === '~') {
                if (preg_match($from, $path)) {
                    $dest = preg_replace($from, $to, $path);
                    $res->redirect($dest, $status);
                    return true;
                }
                continue;
            }

            // Exact match
            if ($path === $from) {
                $res->redirect($to, $status);
                return true;
            }
        }

        return false;
    }

    public function loadRedirects(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $rules = require $file;
        if (is_array($rules)) {
            $this->redirects = $rules;
        }
    }

    /* -------------------------------------------------------------------------
     * Dispatch
     * ------------------------------------------------------------------------- */

    public function dispatch(Request $req, Response $res): void
    {
        // Redirects (highest priority)
        if ($this->applyRedirects($req, $res)) {
            return;
        }

        $method = strtoupper($req->getMethod());
        $path   = $this->normalize($req->getPath());

        if (!$this->loaded) {
            throw new \RuntimeException("Router not loaded. Call loadFromCacheDir() before dispatch().");
        }

        // 1) Static match
        if (isset($this->bucket['static'][$path])) {
            $route = $this->bucket['static'][$path];
            $this->runRoute($route, $req, $res, []);
            return;
        }

        // 2) Dynamic match
        foreach ($this->bucket['dynamic'] as $route) {
            if (!isset($route['regex']) || !is_string($route['regex'])) {
                continue;
            }

            if (preg_match($route['regex'], $path, $m)) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                $this->runRoute($route, $req, $res, $params);
                return;
            }
        }

        if ($this->customRoutes) {
            // 3) Custom routes via hooks (after core routes, before 405/404)
            if (function_exists('apply_filter')) {

                $result = apply_filter('yt_get_custom_route', [null, []], $req, $res);

                // Expected: [$route|null, $params]
                if (is_array($result) && count($result) >= 2) {
                    $route  = $result[0] ?? null;
                    $params = $result[1] ?? [];

                    if ($route !== null) {
                        if (!is_array($params)) {
                            $params = [];
                        }

                        // Optional: allow either full route array or direct handler
                        // If plugin returns handler directly, normalize it.
                        if (is_array($route) && isset($route['handler'])) {
                            $this->runRoute($route, $req, $res, $params);
                            return;
                        }

                        // If plugin returned handler like [Class::class,'method']
                        if (is_array($route) && count($route) === 2) {
                            $this->runRoute(['handler' => $route], $req, $res, $params);
                            return;
                        }

                        throw new \RuntimeException("yt_get_custom_route: invalid route format returned by hook.");
                    }
                }
            }
        }

        if ($this->isMethodNotAllowed($path, $method)) {
            $this->dispatchError(405, $req, $res);
            return;
        }

        $this->dispatchError(404, $req, $res);
        return; 
    }

    /**
     * @param array<string,mixed> $route
     * @param array<string,string> $params
     */
    private function runRoute(array $route, Request $req, Response $res, array $params): void
    {
        $handler = $route['handler'] ?? null;

        // If middleware not enabled, ignore it completely
        if (!$this->middlewareEnabled) {
            $this->invoke($handler, $req, $res, $params);
            return;
        }

        $middleware = $route['middleware'] ?? [];
        if (!is_array($middleware)) {
            $middleware = [];
        }

        $core = function() use ($handler, $req, $res, $params): void {
            $this->invoke($handler, $req, $res, $params);
        };

        $pipeline = $this->buildMiddlewarePipeline($middleware, $core, $req, $res, $params);
        $pipeline();
    }


    /**
     * Builds an executable pipeline:
     *   mw1(mw2(mw3(core)))
     *
     * @param array<int,mixed> $middleware
     * @param callable():void $core
     * @param array<string,string> $params
     */
    private function buildMiddlewarePipeline(
        array $middleware,
        callable $core,
        Request $req,
        Response $res,
        array $params
    ): callable {
        // Normalize list: only strings/callables allowed
        $list = [];
        foreach ($middleware as $mw) {
            if (is_string($mw)) {
                $mw = trim($mw);
                if ($mw !== '') {
                    $list[] = $mw;
                }
                continue;
            }
            if (is_callable($mw)) {
                $list[] = $mw;
            }
        }

        $next = $core;

        // Wrap from last to first
        for ($i = count($list) - 1; $i >= 0; $i--) {
            $mw = $list[$i];

            $resolved = is_string($mw) ? $this->resolveMiddleware($mw) : $mw;

            if (!is_callable($resolved)) {
                throw new \RuntimeException("Invalid middleware resolved for route.");
            }

            $prevNext = $next;

            $next = function() use ($resolved, $req, $res, $prevNext, $params): void {
                // Standard signature:
                // middleware(Request $req, Response $res, callable $next, array $params): void
                $resolved($req, $res, $prevNext, $params);
            };
        }

        return $next;
    }

    /**
     * Resolve middleware string identifier into callable.
     *
     * Supports:
     *  - Resolver map (recommended)
     *  - Class name with __invoke(Request, Response, callable, array)
     *  - "Class@method" callable
     */
    private function resolveMiddleware(string $id): callable
    {
        if ($this->middlewareResolver !== null) {
            $mw = ($this->middlewareResolver)($id);
            return $this->normalizeMiddlewareCallableStaticOnly($mw, $id);
        }
 
        // "Class@method"
        if (str_contains($id, '@')) {
            [$class, $method] = array_map('trim', explode('@', $id, 2));
            if ($class === '' || $method === '') {
                throw new \RuntimeException("Invalid middleware identifier '{$id}'.");
            }
            if (!class_exists($class)) {
                throw new \RuntimeException("Middleware class not found: {$class}");
            }
            $obj = new $class();
            if (!method_exists($obj, $method)) {
                throw new \RuntimeException("Middleware method not found: {$class}::{$method}");
            }
            return [$obj, $method];
        }

        // Class name with __invoke
        if (class_exists($id)) {
            $obj = new $id();
            if (!is_callable($obj)) {
                throw new \RuntimeException("Middleware class '{$id}' is not invokable.");
            }
            return $obj;
        }

        throw new \RuntimeException(
            "Cannot resolve middleware '{$id}'. Provide a middleware resolver or use a valid class/Class@method."
        );
    }

    /* -------------------------------------------------------------------------
     * 405 detection (lazy index load)
     * ------------------------------------------------------------------------- */

    private function isMethodNotAllowed(string $path, string $method): bool
    {
        $key = 'p:' . sha1($path);

        // Load index lazily
        if ($this->index === null) {
            $indexFile = $this->cacheDir . DIRECTORY_SEPARATOR . '__index.php';
            if (!is_file($indexFile) || !is_readable($indexFile)) {
                $this->index = [];
            } else {
                $idx = require $indexFile;
                $this->index = is_array($idx) ? $idx : [];
            }
        }

        if (!isset($this->index[$key]) || !is_array($this->index[$key])) {
            return false;
        }

        return !isset($this->index[$key][$method]);
    }

    /* -------------------------------------------------------------------------
     * Controller invocation
     * ------------------------------------------------------------------------- */

    /**
     * @param mixed $handler Expected: [$class, $method]
     * @param array<string,string> $params
     */
    private function invoke(mixed $handler, Request $req, Response $res, array $params): void
    {
        if (!is_array($handler) || count($handler) !== 2) {
            throw new \RuntimeException("Invalid route handler. Expected [ControllerClass, method].");
        }

        [$class, $method] = $handler;

        if (!is_string($class) || $class === '' || !is_string($method) || $method === '') {
            throw new \RuntimeException("Invalid route handler format.");
        }

        if (!class_exists($class)) {
            throw new \RuntimeException("Controller not found: {$class}");
        }

        $controller = new $class($req, $res);

        if (!method_exists($controller, $method)) {
            throw new \RuntimeException("Method not found: {$class}::{$method}");
        }

        $controller->$method($params);
    }

    /* -------------------------------------------------------------------------
     * Path normalization
     * ------------------------------------------------------------------------- */

    private function normalize(string $p): string
    {
        $p = strtok($p, '?') ?: '/';
        $p = preg_replace('~/+~', '/', $p) ?: '/';
        return $p !== '/' ? rtrim($p, '/') : '/';
    }

    /**
     * Only allow static middleware methods when resolver returns [ClassName, method].
     *
     * Supported return types:
     *  - callable (closure)
     *  - [ClassName::class, 'method'] where method is static
     */
    private function normalizeMiddlewareCallableStaticOnly(mixed $mw, string $idForError): callable
    {
        // Allow closures/callables if you want (optional).
        if (is_callable($mw) && !is_array($mw)) {
            return $mw;
        }

        if (!is_array($mw) || count($mw) !== 2) {
            throw new \RuntimeException("Middleware '{$idForError}' must resolve to [ClassName::class, method].");
        }

        [$class, $method] = $mw;

        if (!is_string($class) || $class === '' || !is_string($method) || $method === '') {
            throw new \RuntimeException("Middleware '{$idForError}' must resolve to [ClassName::class, method].");
        }

        if (!class_exists($class)) {
            throw new \RuntimeException("Middleware class not found for '{$idForError}': {$class}");
        }

        if (!method_exists($class, $method)) {
            throw new \RuntimeException("Middleware method not found for '{$idForError}': {$class}::{$method}");
        }

        $ref = new \ReflectionMethod($class, $method);

        if (!$ref->isStatic()) {
            throw new \RuntimeException("Middleware '{$idForError}' must be a static method: {$class}::{$method}().");
        }

        // Ensure callable
        if (!is_callable([$class, $method])) {
            throw new \RuntimeException("Middleware '{$idForError}' is not callable: {$class}::{$method}().");
        }

        return [$class, $method];
    }

}
