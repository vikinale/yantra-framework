<?php
declare(strict_types=1);
namespace System\Router;

/**
 * Simple URL Router
 * Supports: GET, POST, PUT, DELETE, PATCH with {param} placeholders
 */
final class Router
{
    private static ?self $instance = null;
    private array $routes = [];
    private array $middleware = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function get(string $path, string $handler): self    { return $this->addRoute('GET', $path, $handler); }
    public function post(string $path, string $handler): self   { return $this->addRoute('POST', $path, $handler); }
    public function put(string $path, string $handler): self    { return $this->addRoute('PUT', $path, $handler); }
    public function delete(string $path, string $handler): self { return $this->addRoute('DELETE', $path, $handler); }
    public function patch(string $path, string $handler): self  { return $this->addRoute('PATCH', $path, $handler); }

    public function any(string $path, string $handler): self
    {
        foreach (['GET','POST','PUT','DELETE','PATCH'] as $m) $this->addRoute($m, $path, $handler);
        return $this;
    }

    private function addRoute(string $method, string $path, string $handler): self
    {
        $this->routes[] = [
            'method'  => $method,
            'path'    => $path,
            'handler' => $handler,
            'regex'   => $this->pathToRegex($path),
            'params'  => $this->extractParamNames($path),
        ];
        return $this;
    }

    /**
     * Resolve current request to a route
     * Returns: [handler => "Class@method", params => [key => value]] or null
     */
    public function resolve(string $method, string $uri): ?array
    {
        $method = strtoupper($method);
        $uri = '/' . ltrim($uri, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;

            if (preg_match($route['regex'], $uri, $matches)) {
                $params = [];
                foreach ($route['params'] as $i => $name) {
                    $params[$name] = $matches[$i + 1] ?? null;
                }
                return [
                    'handler' => $route['handler'],
                    'params'  => $params,
                ];
            }
        }

        return null;
    }

    /**
     * Dispatch the current HTTP request
     */
    public function dispatch(string $method, string $uri): mixed
    {
        $resolved = $this->resolve($method, $uri);

        if ($resolved === null) {
            http_response_code(404);
            if ($this->wantsJson()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Route not found: ' . $uri]);
            } else {
                echo '<!DOCTYPE html><html><body><h1>404 Not Found</h1><p>Route not found: ' . htmlspecialchars($uri) . '</p></body></html>';
            }
            return null;
        }

        [$class, $method] = explode('@', $resolved['handler']);
        $params = $resolved['params'];

        if (!class_exists($class)) {
            http_response_code(500);
            echo "Controller not found: {$class}";
            return null;
        }

        $controller = new $class();

        // Call init() if exists (used by our controllers)
        if (method_exists($controller, 'init')) {
            $controller->init();
        }

        if (!method_exists($controller, $method)) {
            http_response_code(500);
            echo "Method not found: {$class}@{$method}";
            return null;
        }

        $response = !empty($params) ? $controller->$method($params) : $controller->$method();

        // If response is a Response object, emit it
        if ($response instanceof \System\Http\Response) {
            $response->emit();
        }

        return $response;
    }

    private function pathToRegex(string $path): string
    {
        // Escape slashes
        $regex = preg_replace('/\//', '\\/', $path);
        // Replace {param} with named capture groups
        $regex = preg_replace('/\{(\w+)\}/', '([^\/]+)', $regex);
        return '/^' . $regex . '$/';
    }

    private function extractParamNames(string $path): array
    {
        preg_match_all('/\{(\w+)\}/', $path, $matches);
        return $matches[1] ?? [];
    }

    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    public function getRoutes(): array { return $this->routes; }

    public static function reset(): void { self::$instance = null; }
}
