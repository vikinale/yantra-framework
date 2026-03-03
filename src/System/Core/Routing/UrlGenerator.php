<?php
declare(strict_types=1);

namespace System\Core\Routing;

/**
 * UrlGenerator — Generates URLs from named routes.
 *
 * Works with the route cache produced by RouteCompiler.
 * Named routes make URLs refactor-proof: changing a URL path in routes.php
 * automatically updates every link in the application.
 *
 * Usage:
 *   $url = UrlGenerator::route('users.show', ['id' => 5]);
 *   // → '/users/5'
 */
final class UrlGenerator
{
    private static ?self $instance = null;

    /**
     * Named routes registry.
     * @var array<string, array{path: string, method: string}>
     */
    private array $namedRoutes = [];

    /**
     * Base URL prefix (e.g. '' or '/subdir').
     */
    private string $baseUrl = '';

    public function __construct()
    {
    }

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set the singleton instance (for testing or custom wiring).
     */
    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Reset the singleton instance.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Set the base URL prefix for generated URLs.
     */
    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Register a named route.
     *
     * @param string $name Route name (e.g. 'users.show')
     * @param string $path Route path pattern (e.g. '/users/{id}')
     * @param string $method HTTP method (e.g. 'GET')
     */
    public function addNamedRoute(string $name, string $path, string $method = 'GET'): void
    {
        $this->namedRoutes[$name] = [
            'path'   => $path,
            'method' => strtoupper($method),
        ];
    }

    /**
     * Bulk-register named routes (e.g. from compiled cache).
     *
     * @param array<string, array{path: string, method: string}> $routes
     */
    public function setNamedRoutes(array $routes): void
    {
        $this->namedRoutes = $routes;
    }

    /**
     * Load named routes from compiled cache directory.
     */
    public function loadFromCacheDir(string $cacheDir): void
    {
        $file = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . '__names.php';
        if (is_file($file)) {
            $data = require $file;
            if (is_array($data)) {
                $this->namedRoutes = $data;
            }
        }
    }

    /**
     * Check if a named route exists.
     */
    public function hasRoute(string $name): bool
    {
        return isset($this->namedRoutes[$name]);
    }

    /**
     * Get all named routes.
     *
     * @return array<string, array{path: string, method: string}>
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }

    /**
     * Generate a URL for a named route.
     *
     * @param string $name Route name.
     * @param array<string, mixed> $params Parameters to substitute into {param} placeholders.
     * @param array<string, mixed> $query Optional query string parameters.
     * @return string Generated URL.
     *
     * @throws \InvalidArgumentException If the route name is not found.
     */
    public function generate(string $name, array $params = [], array $query = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Route [{$name}] not defined.");
        }

        $path = $this->namedRoutes[$name]['path'];

        // Replace {param} and {param:constraint} placeholders
        $url = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/',
            function (array $matches) use ($name, &$params) {
                $paramName = $matches[1];

                if (!array_key_exists($paramName, $params)) {
                    throw new \InvalidArgumentException(
                        "Missing required parameter [{$paramName}] for route [{$name}]."
                    );
                }

                $value = $params[$paramName];
                unset($params[$paramName]);

                return (string) $value;
            },
            $path
        );

        // Remaining unused params go to query string
        $allQuery = array_merge($params, $query);

        $fullUrl = $this->baseUrl . $url;

        if ($allQuery !== []) {
            $fullUrl .= '?' . http_build_query($allQuery);
        }

        return $fullUrl;
    }
}
