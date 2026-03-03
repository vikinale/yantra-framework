<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;

/**
 * MakeMiddlewareCommand — Generates a new PSR-15 compatible middleware stub.
 *
 * Usage:
 *   php bin/yantra make:middleware RateLimitMiddleware
 *   php bin/yantra make:middleware Admin/AuditLogMiddleware
 *   php bin/yantra make:middleware ApiThrottleMiddleware --force
 *
 * Creates a middleware class in App/Middleware/ that follows the framework's
 * middleware signature: function(Request, Response, callable $next, array $params): Response
 */
final class MakeMiddlewareCommand extends AbstractCommand
{
    public function name(): string
    {
        return 'make:middleware';
    }

    public function description(): string
    {
        return 'Create a new middleware class.';
    }

    public function usage(): array
    {
        return [
            'yantra make:middleware RateLimitMiddleware',
            'yantra make:middleware Admin/AuditLogMiddleware',
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $name = $in->arg(0);
        if (!$name) {
            $out->error('Middleware name is required.');
            return 1;
        }

        // Support subdirectories: Admin/FooMiddleware → App\Middleware\Admin\FooMiddleware
        $parts = explode('/', str_replace('\\', '/', $name));
        $className = array_pop($parts);
        $subNamespace = !empty($parts) ? '\\' . implode('\\', $parts) : '';

        $targetPath = (defined('BASEPATH') ? BASEPATH : getcwd())
            . '/App/Middleware/' . $name . '.php';

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\Middleware{$subNamespace};

use System\Http\Request;
use System\Http\Response;

/**
 * {$className}
 *
 * Middleware signature:
 *   function(Request \$req, Response \$res, callable \$next, array \$params): Response
 *
 * Register in your routes:
 *   \$r->get('/path', [Controller::class, 'method'])->middleware('{$this->toMiddlewareId($className)}');
 */
final class {$className}
{
    /**
     * Handle the request.
     *
     * @param Request  \$req    The incoming request.
     * @param Response \$res    The base response.
     * @param callable \$next   The next middleware or controller handler.
     * @param array    \$params Middleware parameters from route definition.
     * @return Response
     */
    public static function handle(Request \$req, Response \$res, callable \$next, array \$params = []): Response
    {
        // --- Before request (pre-processing) ---
        // Example: Check authentication, rate limiting, input validation, etc.

        // Call the next middleware/handler in the pipeline
        \$res = \$next(\$req, \$res);

        // --- After request (post-processing) ---
        // Example: Add headers, log response, modify output, etc.

        return \$res;
    }
}
PHP;

        $this->scaffoldFile($out, $targetPath, $stub, (bool) $this->getOpt($in, 'force'));
        return 0;
    }

    /**
     * Convert a class name to a middleware ID for the registration example.
     * e.g. 'RateLimitMiddleware' → 'rate_limit'
     */
    private function toMiddlewareId(string $className): string
    {
        // Remove 'Middleware' suffix
        $name = preg_replace('/Middleware$/i', '', $className) ?? $className;

        // Convert PascalCase to snake_case
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name);

        return trim($snake, '_');
    }
}
