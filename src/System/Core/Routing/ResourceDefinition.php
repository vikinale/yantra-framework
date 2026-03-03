<?php
declare(strict_types=1);

namespace System\Core\Routing;

/**
 * ResourceDefinition — Fluent configuration for resource routes.
 *
 * Returned by RouteCollector::resource() and RouteCollector::apiResource().
 * Allows further chaining with ->only(), ->except(), ->middleware(), ->name().
 *
 * Usage:
 *   $r->resource('users', UserController::class)->middleware('auth');
 *   $r->resource('posts', PostController::class)->only(['index', 'show']);
 *   $r->resource('comments', CommentController::class)->except(['destroy']);
 */
final class ResourceDefinition
{
    private RouteCollector $collector;
    private int $startIndex;
    private int $endIndex;
    private string $resourceName;
    private string $controller;
    private string $parameter;
    private array $allActions;

    public function __construct(
        RouteCollector $collector,
        int $startIndex,
        int $endIndex,
        string $resourceName,
        string $controller,
        string $parameter,
        array $allActions
    ) {
        $this->collector    = $collector;
        $this->startIndex   = $startIndex;
        $this->endIndex     = $endIndex;
        $this->resourceName = $resourceName;
        $this->controller   = $controller;
        $this->parameter    = $parameter;
        $this->allActions   = $allActions;
    }

    /**
     * Apply middleware to all routes in this resource.
     *
     * Usage:
     *   $r->resource('users', UserController::class)->middleware('auth');
     *   $r->resource('users', UserController::class)->middleware(['auth', 'verified']);
     */
    public function middleware(array|string $mw, array $params = []): self
    {
        $add = $this->collector->_buildMiddlewareAdd($mw, $params);
        $this->collector->_applyMiddlewareToRouteRange($this->startIndex, $this->endIndex, $add);
        return $this;
    }

    /**
     * Apply a name prefix to all routes in this resource.
     *
     * The resource routes already have names like "users.index", "users.show", etc.
     * This adds an additional prefix. Useful within groups.
     *
     * Usage:
     *   $r->resource('users', UserController::class)->name('admin.');
     *   // Names become: admin.users.index, admin.users.show, etc.
     */
    public function name(string $prefix): self
    {
        $this->collector->_applyNamePrefixToRouteRange(
            $this->startIndex,
            $this->endIndex,
            $prefix
        );
        return $this;
    }

    /**
     * Get the resource name.
     */
    public function getResourceName(): string
    {
        return $this->resourceName;
    }

    /**
     * Get the parameter name used in route paths.
     */
    public function getParameter(): string
    {
        return $this->parameter;
    }
}
