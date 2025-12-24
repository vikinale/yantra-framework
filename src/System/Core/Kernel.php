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
    public function __construct(
        private Router $router,
        private string $basePath,
        private string $appPath,
        private string $environment = 'production'
    ) {}

    public function handle(Request $request, Response $response): Response
    {
        try {
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

            // 8) Dispatch
            $this->router->dispatch($request, $response);

            return $response;
        } catch (Throwable $e) {
            return $this->renderException($e, $response);
        }
    }

    private function renderException(Throwable $e, Response $response): Response
    {
        // Keep it simple; improve later (JSON vs HTML negotiation, etc.)
        http_response_code(500);

        if (($this->environment ?? 'production') === 'development') {
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ;
            echo $e->getTraceAsString();
        }

        return $response;
    }
}
