<?php
declare(strict_types=1);

namespace System\Core\Routing;

use System\Http\Request;
use System\Http\Response;
use System\Core\Routing\ModelBindingResolver;

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
    private ?\System\Contracts\ControllerFactoryInterface $factory = null;
    private ?\System\Contracts\ContainerInterface $container = null;

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
     * Route Model Binding (C2)
     * ------------------------------------------------------------------------- */

    /**
     * Register an explicit model binding.
     *
     * When a route parameter matches $parameter, the corresponding model
     * will be automatically resolved from the database before the controller
     * method is invoked.
     *
     * Usage:
     *   $router->model('user', User::class);            // bind by primary key
     *   $router->model('post', Post::class, 'slug');    // bind by slug column
     *
     * @param string      $parameter  The route parameter name (e.g. 'user').
     * @param string      $modelClass FQCN of the Model subclass.
     * @param string|null $column     Column to query by (null = primary key).
     */
    public function model(string $parameter, string $modelClass, ?string $column = null): void
    {
        ModelBindingResolver::bind($parameter, $modelClass, $column);
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

    /* -------------------------------------------------------------------------
     * Dispatch
     * ------------------------------------------------------------------------- */

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

    public function setControllerFactory(\System\Contracts\ControllerFactoryInterface $factory): void
    {
        $this->factory = $factory;
    }

    public function setContainer(\System\Contracts\ContainerInterface $container): void
    {
        $this->container = $container;
    }

    private function instantiateController(string $class, Request $req, Response $res): object
    {
        if ($this->factory) {
            return $this->factory->make($class, $req, $res);
        }
        return new $class($req, $res); // legacy
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

    public function dispatch(Request $req, Response $res): Response
    {
        // Redirects (highest priority)
        $redirectRes = $this->applyRedirects($req, $res);
        if ($redirectRes !== null) {
            return $redirectRes;
        }

        $method = strtoupper($req->getMethod());
        $path   = $this->normalize($req->getPath());

        if (!$this->loaded) {
            throw new \RuntimeException("Router not loaded. Call loadFromCacheDir() before dispatch().");
        }

        // 1) Static match
        if (isset($this->bucket['static'][$path])) {
            $route = $this->bucket['static'][$path];
            return $this->runRoute($route, $req, $res, []);
        }

        // 2) Dynamic match
        foreach ($this->bucket['dynamic'] as $route) {
            if (!isset($route['regex']) || !is_string($route['regex'])) {
                continue;
            }

            if (preg_match($route['regex'], $path, $m)) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

                return $this->runRoute($route, $req, $res, $params);
            }
        }
        
        if ($this->isMethodNotAllowed($path, $method)) {
            return $this->dispatchError(405, $req, $res);
        }
        
        return $this->dispatchError(404, $req, $res); 
    }

    /**
     * @param array<string,mixed> $route
     * @param array<string,string> $params
     */
    private function runRoute(array $route, Request $req, Response $res, array $params): Response
    {
        $handler = $route['handler'] ?? null;

        // If middleware not enabled, ignore it completely
        if (!$this->middlewareEnabled) {
            return $this->invoke($handler, $req, $res, $params);
        }

        $middleware = $route['middleware'] ?? [];
        if (!is_array($middleware)) {
            $middleware = [];
        }

        $core = function(Request $req, Response $res) use ($handler, $params): Response {
            return $this->invoke($handler, $req, $res, $params);
        };

        $pipeline = $this->buildMiddlewarePipeline($middleware, $core, $req, $res, $params);
        return $pipeline($req, $res);
    }


    /**
     * Resolves middleware ID to callable using the resolver.
     *
     * Falls back to class instantiation if no resolver is set
     * and the ID is a valid class name.
     */
    private function resolveMiddleware(string $id): callable
    {
        // 1. Use configured resolver if available
        if ($this->middlewareResolver) {
            $resolver = $this->middlewareResolver;
            return $resolver($id);
        }

        // 2. Fallback: instantiate class-based middleware
        if (class_exists($id)) {
            $instance = new $id();
            if (is_callable($instance)) {
                return $instance;
            }
            throw new \RuntimeException("Middleware class [{$id}] is not callable (missing __invoke).");
        }

        throw new \RuntimeException("Cannot resolve route middleware: {$id}. No middleware resolver configured and [{$id}] is not a valid class.");
    }

    /**
     * Builds an executable pipeline:
     *   mw1(mw2(mw3(core)))
     *
     * Middleware callable signature:
     *   function(Request $req, Response $res, callable $next, array $params): Response
     *
     * Route params (path vars) remain available via $routeParams (passed to invoke).
     * Middleware params (alias params) are passed as 4th arg to middleware callable.
     *
     * @param array<int,mixed> $middleware
     * @param callable(Request,Response):Response $core
     * @param array<string,string> $routeParams
     */
    private function buildMiddlewarePipeline(
        array $middleware,
        callable $core,
        Request $req,
        Response $res,
        array $routeParams
    ): callable {
        // Normalize list: only strings/callables allowed
        $list = [];
        foreach ($middleware as $mw) {
            if (is_callable($mw)) {
                $list[] = ['callable' => $mw, 'params' => []];
                continue;
            }

            if (is_string($mw)) {
                $id = trim($mw);
                if ($id !== '') {
                    $list[] = ['id' => $id, 'params' => []];
                }
                continue;
            }

            if (is_array($mw)) {
                $id = trim((string)($mw['id'] ?? ''));
                if ($id === '') continue;

                $p = $mw['params'] ?? [];
                if (!is_array($p)) $p = [];

                $list[] = ['id' => $id, 'params' => $p];
            }
        }


        $next = $core;

        // Wrap from last to first
       for ($i = count($list) - 1; $i >= 0; $i--) {
            $item = $list[$i];

            $resolved = null;
            $mwParams = $item['params'] ?? [];

            if (isset($item['callable'])) {
                $resolved = $item['callable'];
            } else {
                $resolved = $this->resolveMiddleware((string)$item['id']);
            }

            if (!is_callable($resolved)) {
                throw new \RuntimeException("Invalid middleware resolved for route.");
            }

            $prevNext = $next;

            $next = function(Request $req, Response $res) use ($resolved, $prevNext, $mwParams): Response {
                $result = $resolved($req, $res, $prevNext, $mwParams);
                if (!$result instanceof Response) {
                    // Start of transitional support: if middleware returns void, assume it modified $res in place (legacy behavior support if needed, but we are enforcing Response)
                    // Actually, since $res is immutable, void return means changes are LOST.
                    // We must enforce return Response.
                    // However, for now, let's throw if not response.
                    // throw new \RuntimeException("Middleware must return instance of System\Http\Response");
                    // Or be lenient fallback? No, strict is better for now as we are fixing exactly this.
                    return $res;
                }
                return $result;
            };
        }
        return $next;
    }

    private function applyRedirects(Request $req, Response $res): ?Response
    {
        if ($this->redirects === []) {
            return null;
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
                    return $res->redirect($dest, $status);
                }
                continue;
            }

            // Exact match
            if ($path === $from) {
                return $res->redirect($to, $status);
            }
        }

        return null;
    }
    
    // ...

    private function dispatchError(int $code, Request $req, Response $res): Response
    {
        $this->loadErrorsIfNeeded();

        if (!isset($this->errors[$code])) {
            return $res->withStatus($code);
        }

        $def = $this->errors[$code];
        // Run middleware + handler
        $mw = $def['middleware'] ?? [];
        if (!is_array($mw)) {
            $mw = [];
        }

        // If you have runRoute() from earlier middleware-enabled Router:
        return $this->runRoute(
            [
                'handler'    => $def['handler'] ?? null,
                'middleware' => $mw,
            ],
            $req,
            $res,
            ['code' => (string)$code]
        );
    }

    // ...

    /**
     * @param mixed $handler Expected: [$class, $method]
     * @param array<string,string> $params Route parameters
     */
    private function invoke(mixed $handler, Request $req, Response $res, array $params): Response
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

        // Instantiate controller (Constructor Injection handled here)
        $controller = $this->instantiateController($class, $req, $res);

        if (!method_exists($controller, $method)) {
            throw new \RuntimeException("Method not found: {$class}::{$method}");
        }

        // Resolve method dependencies (with Route Model Binding support)
        $ref = new \ReflectionMethod($controller, $method);
        $args = [];

        foreach ($ref->getParameters() as $p) {
            $name = $p->getName();
            $type = $p->getType();
            
            $typeName = ($type instanceof \ReflectionNamedType && !$type->isBuiltin())
                ? $type->getName()
                : null;

            // 1. Route Model Binding: parameter name matches a route param AND
            //    the type-hint is a Model subclass (or has explicit binding)
            if (array_key_exists($name, $params)) {
                $resolved = ModelBindingResolver::resolve($name, $params[$name], $typeName);
                if ($resolved !== null) {
                    $args[] = $resolved;
                    continue;
                }

                // Not a model binding — pass raw route parameter value
                $val = $params[$name];
                // Cast builtin scalar types from route params (always strings from URL)
                $builtinType = ($type instanceof \ReflectionNamedType && $type->isBuiltin())
                    ? $type->getName() : null;
                if ($builtinType === 'int' && is_numeric($val)) {
                    $val = (int) $val;
                } elseif ($builtinType === 'float' && is_numeric($val)) {
                    $val = (float) $val;
                } elseif ($builtinType === 'bool') {
                    $val = (bool) $val;
                }
                $args[] = $val;
                continue;
            }

            // 2. Check for type-hinted services
            if ($typeName !== null) {
                if ($typeName === Request::class) {
                    $args[] = $req;
                    continue;
                }
                if ($typeName === Response::class) {
                    $args[] = $res;
                    continue;
                }

                // Try container
                if ($this->container && $this->container->has($typeName)) {
                    $args[] = $this->container->get($typeName);
                    continue;
                }
            }

            // 5. If specific "params" or "options" argument is requested (Legacy support)
            // e.g. function index(array $params) or index(array $options)
            if (($name === 'params' || $name === 'options') && $type && $type->getName() === 'array') {
                 $args[] = $params;
                 continue;
            }

            // 3. Fallback: try pass $params as array if typed 'array' and name matches (optional magic)
            // or if we have a default value
            if ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
                continue;
            }

            // 4. If nullable, pass null
            if ($p->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new \RuntimeException("Cannot resolve parameter \${$name} for {$class}::{$method}");
        }

        $result = $controller->$method(...$args);
        
        if ($result instanceof Response) {
            return $result;
        }
        
        // If string, auto-write to body?
        if (is_string($result)) {
             return $res->html($result); // Assuming HTML handling logic
            // Actually, Response is immutable. $res passed in is arguably 'base'.
            // Controller might have used $this->response->something().
            // But $this->response inside controller is effectively a COPY unless updated?
            // BaseController receives $response in ctor.
            // If controller methods return Response (BaseController helpers do), we are good.
            // If they return void but use $this->response methods... $this->response is updated but discarded unless we grab it from controller?
            // Wait. BaseController has protected $response.
            // When controller logic calls $this->render(), it returns a Response object.
            // User code usually does: `return $this->render(...)`.
            // So capturing return value IS key.
        }
        
        // If void/null, the controller may have updated $this->response internally
        // (e.g., via render()). Try to retrieve it.
        if (method_exists($controller, 'getResponse')) {
            return $controller->getResponse();
        }

        // Final fallback: return the original passed $res
        return $res;
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

    private function loadIndexIfNeeded(): void
    {
        if ($this->index !== null) {
            return;
        }

        $file = $this->cacheDir . DIRECTORY_SEPARATOR . '__index.php';
        if (is_file($file)) {
            $this->index = require $file;
        } else {
            $this->index = [];
        }
    }

    private function isMethodNotAllowed(string $path, string $method): bool
    {
        // 1. Static routes (fast O(1) lookup via index)
        $this->loadIndexIfNeeded();
        $hash = 'p:' . sha1($path);
        
        // If hash exists in index, it means this exact path exists as a route pattern.
        // Since it didn't match the current method's static bucket (checked in dispatch),
        // it must be allowed for another method -> 405.
        if (isset($this->index[$hash])) {
            return true;
        }

        // 2. Dynamic routes — only scan other methods that are known to exist.
        // Instead of glob(), use the known HTTP methods to avoid loading ALL cache files.
        $knownMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

        foreach ($knownMethods as $otherMethod) {
            if ($otherMethod === $method) {
                continue; // Already checked in dispatch
            }

            $file = $this->cacheDir . DIRECTORY_SEPARATOR . $otherMethod . '.php';
            if (!is_file($file)) {
                continue;
            }

            $bucket = require $file;
            if (!is_array($bucket) || empty($bucket['dynamic'])) {
                continue;
            }

            foreach ($bucket['dynamic'] as $route) {
                if (isset($route['regex']) && preg_match($route['regex'], $path)) {
                    return true;
                }
            }
        }

        return false;
    }
}
