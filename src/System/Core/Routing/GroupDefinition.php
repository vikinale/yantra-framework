<?php
declare(strict_types=1);

namespace System\Core\Routing;

/**
 * GroupDefinition (fluent config for a group scope)
 * Applies middleware and name prefixes after routes are registered inside the group callback.
 */
final class GroupDefinition
{
    /**
     * @param int[] $errorCodesAdded
     */
    public function __construct(
        private RouteCollector $collector,
        private int $startRouteIndex,
        private int $endRouteIndex,
        private array $errorCodesAdded = []
    ) {}

    /**
     * Usage:
     *  ->middleware('auth')
     *  ->middleware('auth', ['roles'=>'admin'])
     *  ->middleware(['auth','limiter'])
     */
    public function middleware(array|string $mw, array $params = []): self
    {
        $add = $this->collector->_buildMiddlewareAdd($mw, $params);

        $this->collector->_applyMiddlewareToRouteRange(
            $this->startRouteIndex,
            $this->endRouteIndex,
            $add
        );

        if (!empty($this->errorCodesAdded)) {
            $this->collector->_applyMiddlewareToErrors($this->errorCodesAdded, $add);
        }

        return $this;
    }

    /**
     * Apply a name prefix to all routes registered within this group.
     *
     * Usage:
     *   $r->group('/admin', function($r) {
     *       $r->get('/dashboard', [AdminController::class, 'index'])->name('dashboard');
     *   })->name('admin.');
     *   // Route name becomes 'admin.dashboard'
     */
    public function name(string $prefix): self
    {
        $this->collector->_applyNamePrefixToRouteRange(
            $this->startRouteIndex,
            $this->endRouteIndex,
            $prefix
        );

        return $this;
    }
}
