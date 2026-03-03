<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;
use System\Security\Cors;

/**
 * CorsMiddleware — PSR-15 compatible CORS middleware.
 *
 * Wraps the existing System\Security\Cors utility class to provide automatic
 * CORS handling in the middleware pipeline.
 *
 * Configuration:
 *   Load policy from App/Config/cors.php, or pass directly via constructor.
 *
 * Register as middleware:
 *   // In middleware config
 *   'aliases' => [
 *       'cors' => \System\Security\Middleware\CorsMiddleware::class,
 *   ],
 *
 *   // In routes (typically applied to API groups)
 *   $r->group('/api', function($r) {
 *       $r->get('/users', [UserApiController::class, 'index']);
 *   })->middleware('cors');
 *
 * For preflight (OPTIONS) requests, the middleware short-circuits the pipeline
 * and returns a 204 response with appropriate CORS headers.
 */
final class CorsMiddleware
{
    /** @var array<string, mixed> CORS policy */
    private array $policy;

    /**
     * @param array<string, mixed>|null $policy CORS policy. If null, loads from config.
     */
    public function __construct(?array $policy = null)
    {
        if ($policy !== null) {
            $this->policy = $policy;
        } else {
            $this->policy = $this->loadConfig();
        }
    }

    /**
     * Handle the CORS request.
     *
     * @param Request  $req    The incoming request.
     * @param Response $res    The base response.
     * @param callable $next   The next middleware or controller.
     * @param array    $params Middleware parameters (unused).
     * @return Response
     */
    public function __invoke(Request $req, Response $res, callable $next, array $params = []): Response
    {
        $corsResult = Cors::handle($req, $res, $this->policy);

        // If Cors::handle returned a Response, it means CORS headers were applied.
        if ($corsResult instanceof Response) {
            // If preflight (OPTIONS), return immediately without calling downstream handlers
            if (strtoupper($req->getMethod()) === 'OPTIONS') {
                return $corsResult;
            }

            // For non-preflight CORS requests, continue pipeline with CORS headers applied
            $res = $corsResult;
        }

        // Continue to next middleware / controller
        return $next($req, $res);
    }

    /**
     * Load CORS configuration from the config file.
     *
     * Expected file: App/Config/cors.php
     * Expected format:
     *   return [
     *       'allowed_origins' => ['*'],
     *       'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
     *       'allowed_headers' => ['Content-Type', 'Authorization', 'X-CSRF-TOKEN'],
     *       'expose_headers'  => [],
     *       'credentials'     => false,
     *       'max_age'         => 86400,
     *   ];
     *
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        $configFile = (defined('BASEPATH') ? BASEPATH : getcwd()) . '/App/Config/cors.php';

        if (is_file($configFile)) {
            $config = require $configFile;
            if (is_array($config)) {
                return $this->normalizeConfig($config);
            }
        }

        // Sensible defaults: restrict everything
        return [
            'origins'        => [],
            'methods'        => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'headers'        => ['Content-Type', 'Authorization', 'X-CSRF-TOKEN'],
            'expose_headers' => [],
            'credentials'    => false,
            'max_age'        => 600,
        ];
    }

    /**
     * Normalize config keys to match the Cors class expected format.
     *
     * Accepts both developer-friendly keys (allowed_origins) and internal keys (origins).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeConfig(array $config): array
    {
        return [
            'origins'        => $config['allowed_origins'] ?? $config['origins'] ?? [],
            'methods'        => $config['allowed_methods'] ?? $config['methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'headers'        => $config['allowed_headers'] ?? $config['headers'] ?? ['Content-Type', 'Authorization', 'X-CSRF-TOKEN'],
            'expose_headers' => $config['expose_headers'] ?? [],
            'credentials'    => (bool)($config['credentials'] ?? false),
            'max_age'        => (int)($config['max_age'] ?? 600),
        ];
    }
}
