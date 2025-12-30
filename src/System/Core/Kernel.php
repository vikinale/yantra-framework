<?php
declare(strict_types=1);

namespace System\Core;

use System\Core\Routing\Router;
use System\Http\Request;
use System\Http\Response;
use RuntimeException;
use Throwable;

final class Kernel
{
    /** @var array<int, mixed> */
    private array $globalMiddleware = [];

    /** @var null|callable(string):callable */
    private $middlewareResolver = null;

    public function __construct(
        private Router $router,
        private string $basePath,
        private string $appPath,
        private string $environment = 'production'
    ) {}

    /**
     * Global middleware (runs for every request, including 404/405).
     *
     * Each item can be:
     * - string id (resolved by middleware resolver)
     * - callable(Request $req, Response $res, callable $next, array $params): void
     */
    public function setGlobalMiddleware(array $middleware): void
    {
        $this->globalMiddleware = $middleware;
    }

    /**
     * Resolver to convert string middleware IDs into callables.
     */
    public function setMiddlewareResolver(callable $resolver): void
    {
        $this->middlewareResolver = $resolver;
    }

    public function handle(Request $request, Response $response): Response
    {
        try {
            $core = function () use ($request, $response): void {

                // 5) Dev: compile routes from source (exactly as you had)
                $routesSourceFile = $this->basePath . '/app/config/routes.php';
                if ($this->environment === 'development' && is_file($routesSourceFile)) {
                    $routesDefinition = require $routesSourceFile;
                    if (!is_callable($routesDefinition)) {
                        throw new RuntimeException("Routes file must return a callable(RouteCollector): void");
                    }
                    $this->router->compileAndCache($routesDefinition, true);
                }

                // 6) Load cached routes for current method
                $this->router->loadFromCacheDir($request->getMethod());

                // Optional: hooks point once routes are loaded
                if (function_exists('do_action')) {
                    do_action('init_routes');
                }

                // 7) Redirects (optional)
                $redirectsFile = $this->basePath . '/config/redirects.php';
                if (is_file($redirectsFile) && method_exists($this->router, 'loadRedirects')) {
                    $this->router->loadRedirects($redirectsFile);
                }

                // 8) Dispatch (route-level middleware is handled inside Router)
                $this->router->dispatch($request, $response);
            };

            // Wrap dispatch with global middleware pipeline
            if ($this->globalMiddleware !== []) {
                $pipeline = $this->buildPipeline(
                    $this->globalMiddleware,
                    $core,
                    $request,
                    $response,
                    [] // global params
                );
                $pipeline();
            } else {
                $core();
            }

            return $response;
        } catch (Throwable $e) {
            return $this->renderException($e, $response);
        }
    }

    private function buildPipeline(
        array $middleware,
        callable $core,
        Request $req,
        Response $res,
        array $params
    ): callable {
        $list = [];
        foreach ($middleware as $mw) {
            if (is_string($mw)) {
                $mw = trim($mw);
                if ($mw !== '') $list[] = $mw;
                continue;
            }
            if (is_callable($mw)) {
                $list[] = $mw;
            }
        }

        $next = $core;

        for ($i = count($list) - 1; $i >= 0; $i--) {
            $mw = $list[$i];

            $resolved = is_string($mw) ? $this->resolveMiddleware($mw) : $mw;

            if (!is_callable($resolved)) {
                throw new RuntimeException("Invalid global middleware resolved.");
            }

            $prevNext = $next;

            $next = function() use ($resolved, $req, $res, $prevNext, $params): void {
                $resolved($req, $res, $prevNext, $params);
            };
        }

        return $next;
    }

    private function resolveMiddleware(string $id): callable
    {
        if ($this->middlewareResolver === null) {
            throw new RuntimeException("Kernel middleware resolver not set. Call Kernel::setMiddlewareResolver().");
        }

        $mw = ($this->middlewareResolver)($id);
        if (!is_callable($mw)) {
            throw new RuntimeException("Kernel cannot resolve middleware '{$id}'.");
        }
        return $mw;
    }

    private function renderException(Throwable $e, Response $response): Response
    {
        http_response_code(500);

        if (($this->environment ?? 'production') === 'development') {
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            echo $e->getTraceAsString();
        }

        return $response;
    }
}
