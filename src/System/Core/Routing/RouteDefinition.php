<?php
declare(strict_types=1);

namespace System\Core\Routing;

/**
 * RouteDefinition (fluent config for the last added route)
 *  - ->middleware(...)
 *  - ->name(...)
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
     *  ->middleware(['auth','limiter'])
     */
    public function middleware(array|string $mw, array $params = []): self
    {
        $routes  = $this->collector->getRoutes();
        $current = $routes[$this->index]['middleware'] ?? [];
        if (!is_array($current)) $current = [];

        $add    = $this->collector->_buildMiddlewareAdd($mw, $params);
        $merged = $this->collector->_mergeMiddlewareLists($current, $add);

        $this->collector->_setMiddleware($this->index, $merged);
        return $this;
    }

    /**
     * Assign a name to this route.
     *
     * Usage:
     *  $r->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
     */
    public function name(string $name): self
    {
        $this->collector->_setName($this->index, $name);
        return $this;
    }
}
