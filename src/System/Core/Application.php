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
    private string $appPath;
    private array $config = [];
    private string $environment;

    private Router $router;
    private Kernel $kernel;

    private ViewRenderer $views;
    private ?ThemeManager $theme = null;

    public function __construct(string $appDir, string $environment = 'production')
    {
        $this->appPath = $this->resolveAppPath($appDir);
        $this->environment = $environment;

        $helpers = __DIR__ . '/../functions.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
    }

    public static function create(string $appDir = 'app', string $environment = 'production'): self
    {
        return new self($appDir, $environment);
    }

    public static function getBasePath(string $append = ''): string
    {
        return rtrim(BASEPATH, DIRECTORY_SEPARATOR) . ($append ? DIRECTORY_SEPARATOR . $append : '');
    }

    public function getAppPath(string $append = ''): string
    {
        return rtrim($this->appPath, DIRECTORY_SEPARATOR) . ($append ? DIRECTORY_SEPARATOR . $append : '');
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

    public function initRoutes(): self
    {
        if (!isset($this->router)) {
            $this->router = new Router(self::getBasePath('storage/cache/routes'));
        }

        if ($this->environment === 'development') {
            $routesSourceFile = self::getBasePath('app/config/routes.php');

            if (!is_file($routesSourceFile)) {
                throw new RuntimeException("Routes file not found: {$routesSourceFile}");
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
        Config::setAppPath($this->appPath);

        // Load app config once
        $this->config = Config::get('app') ?? [];

        // Decide environment precedence:
        // Option A (recommended): constructor arg wins if provided, else config.
        // If you want config to override always, change this line accordingly.
        $cfgEnv = (string)($this->config['environment'] ?? '');
        if ($cfgEnv !== '') {
            $this->environment = $cfgEnv;
        }

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
        $this->views = new ViewRenderer([$this->getAppPath('Views')]);

        // Theme (optional)
        $themeCfg = is_array($this->config['theme'] ?? null) ? $this->config['theme'] : [];
        $enabled  = (bool)($themeCfg['enabled'] ?? false);
 
        if ($enabled) {
            $registry = new ThemeRegistry((string)($themeCfg['root'] ?? self::getBasePath('themes')));

            $this->theme = new ThemeManager(
                registry: $registry,
                views: $this->views,
                enabled: true,
                fallbackToViews: (bool)($themeCfg['fallback_to_views'] ?? true),
                activeSlug: isset($themeCfg['active']) ? (string)$themeCfg['active'] : null,
                publicBaseUrl:site_url()
            );

            $this->theme->boot();
        } else {
            $this->theme = null;
        }

        // Controller factory (inject views + optional theme)
        $factory = new \System\Core\ControllerFactory($this->theme);
        $this->router->setControllerFactory($factory);

        // Kernel
        $this->kernel = new Kernel(
            router: $this->router,
            basePath: BASEPATH,
            appPath: $this->appPath,
            environment: $this->environment
        );

        $securityResolver = function (string $id): callable {
            return match ($id) {
                'sec.cookies'     => new \System\Security\Middleware\CookieHardeningMiddleware(),
                'sec.normalize'   => new \System\Security\Middleware\RequestNormalizationMiddleware(),
                'sec.headers:web' => new \System\Security\Middleware\SecurityHeadersMiddleware('web'),
                'sec.csrf'        => new \System\Security\Middleware\CsrfMiddleware(),
                'sec.audit'       => new \System\Security\Middleware\AuditMiddleware(),
                'sec.auth'        => new \System\Security\Middleware\AuthGuardMiddleware(),
                'sec.csp:web'   => new \System\Security\Middleware\CspNonceMiddleware('web'),
                'sec.csp:admin' => new \System\Security\Middleware\CspNonceMiddleware('admin'),
                'sec.login_throttle' => new \System\Security\Middleware\LoginThrottleMiddleware(8, 600),
                'sec.admin'       => function($req,$res,$next,$params){
                    $mw = new \System\Security\Middleware\AuthGuardMiddleware();
                    $mw($req,$res,$next, ['roles'=>'admin','redirect'=>'/login']);
                },
                default => throw new \RuntimeException("Unknown middleware: {$id}"),
            };
        };

        // Router route/group middleware
        $this->enableMiddleware($securityResolver);

        // Kernel global middleware + resolver
        $this->kernel->setMiddlewareResolver($securityResolver);
        $this->router->setMiddlewareResolver($securityResolver);

        $this->kernel->setGlobalMiddleware([
            'sec.normalize',
            'sec.cookies',
            'sec.headers:web',
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

        // Ensure routes are loaded/compiled according to env
        $this->initRoutes();

        $request  = new Request();

        // IMPORTANT: Response should use the same ViewRenderer created in boot()
        $response = new Response();
        if (method_exists($response, 'setViewRenderer')) {
            $response->setViewRenderer($this->views);
        }

        $response = $this->kernel->handle($request, $response);

        if (method_exists($response, 'emit')) {
            $response->emit();
            return;
        }

        throw new RuntimeException('Response::emit() not implemented. Please implement a single emission point.');
    }

    private function resolveAppPath(string $appDir): string
    {
        $appDir = rtrim($appDir, '/\\');
        if ($appDir === '') {
            throw new RuntimeException('Application directory cannot be empty.');
        }

        // Absolute path (Windows or Unix)
        $isAbs = str_starts_with($appDir, DIRECTORY_SEPARATOR) || preg_match('~^[A-Za-z]:[\\\\/]~', $appDir) === 1;
        $path = $isAbs ? $appDir : rtrim(BASEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $appDir;

        return rtrim($path, DIRECTORY_SEPARATOR);
    }
}
