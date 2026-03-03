<?php
declare(strict_types=1);

namespace System\Core;

use RuntimeException;
use System\Core\Routing\Router;
use System\Http\Request;
use System\Http\Response;
use Throwable;

final class Kernel
{
    /** @var array<int, mixed> */
    private array $globalMiddleware = [];

    /**
     * Middleware groups: group name → list of middleware IDs.
     *
     * Example:
     *   'web' => ['sec.normalize', 'sec.headers', 'sec.cookies', 'sec.csrf'],
     *   'api' => ['sec.normalize', 'sec.headers', 'auth.jwt', 'rate.limit'],
     *
     * @var array<string, array<int, string>>
     */
    private array $middlewareGroups = [];

    /**
     * Middleware aliases: short name → FQCN.
     *
     * Example:
     *   'auth'     => \System\Security\Middleware\AuthGuardMiddleware::class,
     *   'throttle' => \System\Security\Middleware\RateLimitMiddleware::class,
     *
     * @var array<string, string>
     */
    private array $middlewareAliases = [];

    public function __construct(
        private Router $router,
        private string $environment = 'production',
        private ?\System\Contracts\LoggerInterface $logger = null
    ) {}

    /**
     * @var null|callable(string):callable
     */
    private $middlewareResolver = null;

    public function setMiddlewareResolver(callable $resolver): void
    {
        $this->middlewareResolver = $resolver;
    }

    public function setGlobalMiddleware(array $middleware): void
    {
        $this->globalMiddleware = $middleware;
    }

    /**
     * Register middleware groups.
     *
     * Groups allow applying multiple middleware with a single name.
     * When a group name is used as middleware, it expands to all middleware in the group.
     *
     * Usage:
     *   $kernel->setMiddlewareGroups([
     *       'web' => ['sec.normalize', 'sec.headers', 'sec.cookies', 'sec.csrf'],
     *       'api' => ['sec.normalize', 'sec.headers', 'auth.jwt', 'rate.limit'],
     *   ]);
     *
     * @param array<string, array<int, string>> $groups
     */
    public function setMiddlewareGroups(array $groups): void
    {
        $this->middlewareGroups = $groups;
    }

    /**
     * Register middleware aliases.
     *
     * Aliases map short names to fully qualified class names for convenient route definitions.
     *
     * Usage:
     *   $kernel->setMiddlewareAliases([
     *       'auth'     => \System\Security\Middleware\AuthGuardMiddleware::class,
     *       'guest'    => \System\Security\Middleware\GuestOnlyMiddleware::class,
     *       'throttle' => \System\Security\Middleware\RateLimitMiddleware::class,
     *   ]);
     *
     * @param array<string, string> $aliases
     */
    public function setMiddlewareAliases(array $aliases): void
    {
        $this->middlewareAliases = $aliases;
    }

    /**
     * Load middleware configuration from a config file.
     *
     * Expected format:
     *   return [
     *       'groups' => [
     *           'web' => ['sec.normalize', 'sec.headers', 'sec.cookies', 'sec.csrf'],
     *           'api' => ['sec.normalize', 'sec.headers', 'auth.jwt'],
     *       ],
     *       'aliases' => [
     *           'auth'     => AuthGuardMiddleware::class,
     *           'throttle' => RateLimitMiddleware::class,
     *       ],
     *   ];
     *
     * @param string $configFile Path to middleware.php config file.
     */
    public function loadMiddlewareConfig(string $configFile): void
    {
        if (!is_file($configFile)) {
            return;
        }

        $config = require $configFile;
        if (!is_array($config)) {
            return;
        }

        if (isset($config['groups']) && is_array($config['groups'])) {
            $this->middlewareGroups = $config['groups'];
        }

        if (isset($config['aliases']) && is_array($config['aliases'])) {
            $this->middlewareAliases = $config['aliases'];
        }
    }

    /**
     * Expand a middleware identifier, resolving groups and aliases.
     *
     * @param string $id Middleware ID (could be a group name, alias, or class name).
     * @return array<int, string> Expanded list of middleware class/ID strings.
     */
    public function expandMiddleware(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return [];
        }

        // 1. Check if it's a group name
        if (isset($this->middlewareGroups[$id])) {
            $expanded = [];
            foreach ($this->middlewareGroups[$id] as $groupMw) {
                // Recursively expand (groups can reference aliases or other groups)
                $expanded = array_merge($expanded, $this->expandMiddleware((string)$groupMw));
            }
            return $expanded;
        }

        // 2. Check if it's an alias
        if (isset($this->middlewareAliases[$id])) {
            return [$this->middlewareAliases[$id]];
        }

        // 3. Return as-is (FQCN or existing middleware ID)
        return [$id];
    }

    public function handle(Request $request, Response $response): Response
    {
        try {
            $core = function (Request $req, Response $res) : Response {
                $this->router->loadFromCacheDir($req->getMethod());
                if (function_exists('apply_filters')) {
                    $this->router = apply_filters('init_routes', $this->router);
                }
                return $this->router->dispatch($req, $res);
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
                return $pipeline($request, $response);
            } 
            
            return $core($request, $response);

        } catch (Throwable $e) {
            // Log the exception
            if ($this->logger) {
                $this->logger->error($e->getMessage(), [
                    'exception' => $e,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
            
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
                if ($mw === '') continue;

                // Expand groups and aliases
                $expanded = $this->expandMiddleware($mw);
                foreach ($expanded as $e) {
                    $list[] = $e;
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
            $item = $list[$i];
            
            // Resolve
            $resolved = null;
            if (is_callable($item)) {
                $resolved = $item;
            } elseif (is_string($item)) {
                $resolved = $this->resolveMiddleware($item);
            }

            if (!is_callable($resolved)) {
                 throw new RuntimeException("Invalid global middleware.");
            }

            $prevNext = $next;
            $next = function(Request $req, Response $res) use ($resolved, $prevNext): Response {
                $result = $resolved($req, $res, $prevNext, []);
                if (!$result instanceof Response) {
                    // Fallback for void returns?
                    return $res;
                }
                return $result;
            };
        }

        return $next;
    }

    private function resolveMiddleware(string $id): callable
    {
        if ($this->middlewareResolver !== null) {
            $resolver = $this->middlewareResolver;
            return $resolver($id);
        }

        // Basic class-based fallback if no resolver
        // "Class@method"
        if (str_contains($id, '@')) {
            [$class, $method] = array_map('trim', explode('@', $id, 2));
            if (class_exists($class)) {
                $obj = new $class();
                if (method_exists($obj, $method)) {
                    return [$obj, $method];
                }
            }
        }
        // Invokeable class
        if (class_exists($id) && is_callable(new $id)) {
            return new $id;
        }

        throw new RuntimeException("Cannot resolve global middleware '{$id}' (no resolver set).");
    }

    private function renderException(Throwable $e, Response $response): Response
    {
        // Use centralized ErrorHandler
        $handler = new ErrorHandler($this->logger, $this->environment);
        
        // ErrorHandler::handle requires Request, but we don't have it in this context
        // Create a minimal Request from globals as fallback
        $request = new Request();
        
        return $handler->handle($e, $request, $response);
    }
}
