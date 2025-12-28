<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;
use System\Config;
use System\Core\Routing\Router;
use System\Core\Routing\RouteCollector;
use RuntimeException;

final class RoutesCacheCommand extends AbstractCommand
{
    public function name(): string { return 'routes:cache'; }

    public function description(): string
    {
        return 'Compile and write route cache files (GET.php, POST.php, __index.php, __errors.php).';
    }

    public function usage(): array
    {
        return [
            "yantra routes:cache --base=./ --app=app --cache=storage/cache/routes",
            "yantra routes:cache",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $base  = (string)($in->option('base', getcwd() ?: '.'));
        $app   = (string)($in->option('app', 'app'));
        $cache = (string)($in->option('cache', 'storage/cache/routes'));
        $force = (bool)($in->option('force', false));

        $base = rtrim($base, "/\\");
        $appPath = $base . DIRECTORY_SEPARATOR . trim($app, "/\\");
        $cacheDir = $base . DIRECTORY_SEPARATOR . trim($cache, "/\\");

        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true)) {
            throw new RuntimeException("Cannot create cache dir: {$cacheDir}");
        }

        // Configure paths for Config usage (Yantra conventions)
        if (defined('BASEPATH') === false) {
            // CLI may not define BASEPATH; but Config wants a base path.
            // If your framework mandates BASEPATH constant, define it in bin/yantra.
        }
        Config::setBasePath($base);
        Config::setAppPath($appPath);

        $routesFile = $appPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'routes.php';
        if (!is_file($routesFile)) {
            throw new RuntimeException("Routes file not found: {$routesFile}");
        }

        $routesDef = require $routesFile;
        if (!is_callable($routesDef)) {
            throw new RuntimeException("routes.php must return callable(RouteCollector): void");
        }

        $router = new Router($cacheDir);
        $router->compileAndCache($routesDef, $force);

        $out->writeln(Style::ok("Routes cached successfully."));
        $out->writeln("Cache: {$cacheDir}");
        return 0;
    }
}
