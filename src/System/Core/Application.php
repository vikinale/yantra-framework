<?php
declare(strict_types=1);

namespace System\Core;

use RuntimeException;
use System\Config;
use System\Core\Routing\Router;
use System\Http\Request;
use System\Http\Response;
use System\Utilities\SessionStore;
use System\Utilities\NativeSessionAdapter;
use System\Utilities\SessionAdapterInterface;
use System\View\ViewRenderer;
use System\Theme\ThemeRegistry;
use System\Theme\ThemeManager;

final class Application
{
    private array $config = [];
    private string $environment;

    private Router $router;
    private Kernel $kernel;

    private ViewRenderer $views;

    public function __construct(?string $environment)
    {
        $this->config = Config::get('app') ?? [];
        if($environment)
           $this->environment = $environment;
        else{
            $this->environment = (string)($this->config['environment'] ?? 'production');
        }
        $helpers = __DIR__ . '/../functions.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
    }

    public static function create(string $environment = 'production'): self
    {
        return new self($environment);
    }

    public static function getBasePath(string $append = ''): string
    {
        return rtrim(BASEPATH, DIRECTORY_SEPARATOR) . ($append ? DIRECTORY_SEPARATOR . $append : '');
    }

    public function addSessionAdapter(SessionAdapterInterface $adapter): self
    {
        SessionStore::init($adapter);
        return $this;
    }

    public static function dbConfig(): array
    {
        $dbConfig = Config::get('db');
        if (empty($dbConfig) || !is_array($dbConfig)) {
            throw new RuntimeException("Invalid DB configuration. Check db.php in configuration directory.");
        }
        return $dbConfig;
    }

    public function initRoutes(?string $routesSourceFile): self
    {
        if (!isset($this->router)) {
            $this->router = new Router(self::getBasePath('storage/cache/routes'));
        }
        if ($this->environment === 'development') {
            if ($routesSourceFile && is_file($routesSourceFile)) {
                $routesDefinition = require $routesSourceFile;
                if (!is_callable($routesDefinition)) {
                    throw new RuntimeException("Routes file must return a callable(RouteCollector): void");
                }
                $this->router->compileAndCache($routesDefinition, true);
            }
        }
        return $this;
    }

    public function boot(): self
    {
        if ($this->environment === 'development') {
            ini_set('display_errors', '1');
            ini_set('log_errors', '1');
            ini_set('error_log', self::getBasePath('storage/logs/error.log'));
            error_reporting(E_ALL & ~E_NOTICE);
        }
        // Sessions
        if (!SessionStore::is_init()) {
            SessionStore::init(new NativeSessionAdapter([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]));
        }
        // Router
        if (!isset($this->router)) {
            $this->router = new Router(self::getBasePath('storage/cache/routes'));
        }
        // Views (single instance)
        $this->views = new ViewRenderer([app_path('Views')]);
 
        // Controller factory (inject views + optional theme)
        $factory = new \System\Core\ControllerFactory();
        $this->router->setControllerFactory($factory);

        // Kernel
        $this->kernel = new Kernel(
            router: $this->router,
            environment: $this->environment
        );
        
        $this->kernel->setGlobalMiddleware([
            'sec.normalize',
            'sec.cookies',
            'sec.audit',
        ]);

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
        $response->setViewRenderer($this->views);
        $response = $this->kernel->handle($request, $response);
        $response->emit();
    }
}