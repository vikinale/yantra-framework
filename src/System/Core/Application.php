<?php
declare(strict_types=1);

namespace System\Core;

use System\Config;
use System\Core\Routing\Router;
use System\Http\Request;
use System\Http\Response;
use System\Utilities\SessionStore;
use System\Utilities\NativeSessionAdapter;
use App\Middleware\MiddlewareMap;
use RuntimeException;
use System\Utilities\SessionAdapterInterface;
use Throwable;

final class Application
{
    private string $basePath;
    private string $appPath;
    private array $config = [];
    private string $environment = 'development';

    private Router $router;
    private Kernel $kernel;

    public function __construct(string $basePath, string $environment = 'development')
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->appPath  = $this->basePath . '/app';
        $this->environment = $environment;
    }

    public static function create(string $basePath): self
    {
        return new self($basePath);
    }

    public function getBasePath(string $append=''): string
    {
        return $this->basePath . ($append ? DIRECTORY_SEPARATOR . $append : '');
    }

    public function getAppPath(string $append=''): string
    {
        return $this->appPath . ($append ? DIRECTORY_SEPARATOR . $append : '');
    }

    public function addSessionAdapter(SessionAdapterInterface $adapter): self
    {
        SessionStore::init($adapter);
        return $this;
    }

    public function initRoutes(): self
    {
        if (!isset($this->router)) {
            $routeCacheDir = $this->basePath . '/storage/cache/routes';
            $this->router = new Router($routeCacheDir);
        }
        if ($this->environment === 'development'){
            $routesSourceFile = BASEPATH . '/app/config/routes.php';
            if (!is_file($routesSourceFile)) {
                throw new RuntimeException("Routes file not found at expected location: {$routesSourceFile}");
            }

            $routesDefinition = require $routesSourceFile;
            if (!is_callable($routesDefinition)) {
                    throw new RuntimeException("Routes file must return a callable(RouteCollector): void");
            }

            $this->router->compileAndCache($routesDefinition, true);
        }
        return $this;
    }

    public function boot(): self
    {
        // 1) Config (your key is 'app', not 'App')
        Config::setBasePath($this->basePath);
        $this->config = Config::get('app') ?? [];
        $this->environment = (string)($this->config['environment'] ?? 'production');

        if ($this->environment === 'development') {
            ini_set('display_errors', '1');
            ini_set('log_errors', '1');
            ini_set('error_log', $this->basePath . '/storage/logs/error.log');
            error_reporting(E_ALL & ~E_NOTICE);
        }

        if (!SessionStore::is_init())  {
            SessionStore::init(new NativeSessionAdapter([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]));
        }

        if (!isset($this->router)) {
            $routeCacheDir = $this->basePath . '/storage/cache/routes';
            $this->router = new Router($routeCacheDir); 
        }

        $this->kernel = new Kernel(
            router: $this->router,
            basePath: $this->basePath,
            appPath: $this->appPath,
            environment: $this->environment
        );

        return $this;
    }

    public function setRoute(Router $router): self
    {
        $this->router = $router;
        return $this;
    }

    public function setCacheDir(string $dir): self
    {
        $this->router->setCacheDir($dir);
        return $this;
    }

    public function enableMiddleware(callable $resolver): self
    {
        $this->router->setMiddlewareResolver($resolver);
        $this->router->enableMiddleware();
        return $this;
    }

    public function run(): void
    {
        if (!isset($this->kernel)) {
            $this->boot();
        }

        $request  = new Request();
        $response = new Response();

        $response = $this->kernel->handle($request, $response);

        // Single emission point (preferred)
        if (method_exists($response, 'emit')) {
            $response->emit();
            return;
        }

        // If Response currently does not implement emit(), then your router/controller
        // must be echoing. Implement Response::emit() ASAP.
    }
}