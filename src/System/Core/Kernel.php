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

    public function __construct(
        private Router $router,
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

    public function handle(Request $request, Response $response): Response
    {
        try {
            $core = function () use ($request, $response): void {
                $this->router->loadFromCacheDir($request->getMethod());
                if (function_exists('do_action')) {
                    $this->router = apply_filters('init_routes', $this->router);
                }
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

        return $core;
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
