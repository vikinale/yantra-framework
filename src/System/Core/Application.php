<?php
declare(strict_types=1);

namespace System\Core;

use FilesystemIterator;
use RuntimeException;
use System\Cli\CommandRegistry;
use System\Config;
use System\Config\ConfigRepository;
use System\Contracts\ConfigInterface;
use System\Contracts\SessionAdapterInterface;
use System\Core\Routing\Router;
use System\Http\Request;
use System\Http\Response;
use System\Session\NativeSessionAdapter;
use System\Session\SessionStore;
use System\View\ViewRenderer;


final class Application
{
    private array $config = [];
    private string $environment;

    private Router $router;


    private ViewRenderer $views;
    private ?CommandRegistry $cli = null;
    private Kernel $kernel;
    private \System\Contracts\ContainerInterface $container;

    public function __construct(?string $environment)
    {
        // Load .env if available.
        //
        // A present-but-unparseable .env MUST fail loudly: silently falling
        // back to config defaults once caused migrate/seed to run against the
        // wrong database. We strip whole-line `#`/`;` comments first (dotenv
        // conventionally uses `#`, which parse_ini_* treats as data), then
        // parse; if the file exists but still won't parse, we throw.
        $rootParams = self::getBasePath();
        $envFile = $rootParams . '/.env';
        if (is_file($envFile)) {
            $raw = (string) file_get_contents($envFile);
            $stripped = preg_replace('/^[ \t]*[#;].*$/m', '', $raw);
            $parsedOk = @parse_ini_string((string) $stripped, false, INI_SCANNER_RAW);
            if (!is_array($parsedOk)) {
                $err = error_get_last()['message'] ?? 'unknown parse error';
                throw new \RuntimeException(sprintf(
                    'Failed to parse %s (%s). Refusing to boot with default '
                    . 'config — fix the .env (use `;` or whole-line `#` for '
                    . 'comments; quote values containing ( ) { } | & ! ~).',
                    $envFile,
                    $err
                ));
            }
            foreach ($parsedOk as $key => $value) {
                $key = trim((string)$key);
                $value = trim((string)$value);
                if ($key !== '') {
                    putenv(sprintf('%s=%s', $key, $value));
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }

        // Phase 5: Create ConfigRepository instance before container
        // (Config is needed to build the container itself — chicken-and-egg)
        $configRepo = new ConfigRepository(APPPATH, 'Config');
        Config::setInstance($configRepo);

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

        // Initialize Native Container
        $this->container = new \System\Core\Container();

        // Load framework defaults first
        $frameworkConfig = dirname(__DIR__, 3) . '/config/dependencies.php';
        if (file_exists($frameworkConfig)) {
            $this->container->addDefinitions($frameworkConfig);
        }

        $depFile = self::getBasePath('config/dependencies.php');
        if (file_exists($depFile)) {
            $this->container->addDefinitions($depFile);
        }

        // Phase 5: Register interface bindings in DI container
        // ConfigInterface uses the same instance created above (pre-container)
        // Note: $container->set() accepts raw values; lazy bindings are
        // already defined in config/dependencies.php as factory closures.
        $this->container->set(ConfigInterface::class, $configRepo);
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
            return []; // No DB configured — app may not need one.
        }
        return $dbConfig;
    }
    public function initRoutes(string|array|null $routesSourceFile): self
    {
        // Normalize routes input to array<string, array<string>>
        $normalized = [];

        if (is_array($routesSourceFile)) {
            foreach ($routesSourceFile as $scope => $file) {
                if (is_string($file)) {
                     $normalized[$scope][] = $file;
                } elseif (is_array($file)) {
                     foreach($file as $f) $normalized[$scope][] = $f;
                }
            }
        } elseif (is_string($routesSourceFile) && $routesSourceFile !== '') {
            $normalized['web'][] = $routesSourceFile;
        }
        
        foreach ($normalized as $scope => $files) {
            foreach ($files as $file) {
                $this->addRouteFile((string)$scope, (string)$file);
            }
        }
        // In development, compile all scopes eagerly (fast iteration)
        if ($this->environment === 'development') {
            foreach ($this->routeFiles as $scope => $files) {
                 $this->ensureScopeCache((string)$scope, true);
            }
        }

        return $this;
    }

    public function addRouteFile(string $scope, string $file): self
    {
        if (!isset($this->routeFiles[$scope])) {
            $this->routeFiles[$scope] = [];
        }
        if (!in_array($file, $this->routeFiles[$scope], true)) {
            $this->routeFiles[$scope][] = $file;
        }
        return $this;
    }


    private function clearRouteCache(): void
    {
        $dir = self::getBasePath('storage/cache/routes');
        if (!is_dir($dir)) return;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
    }

    public function boot(): self
    {
        $this->initDevErrorLogging();
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
        // Router: load only scoped route cache
        if (!isset($this->router)) {
            $scope = $this->detectRouteScope();
            $this->ensureScopeCache($scope); // compile if cache missing
            $this->router = new Router($this->cacheDirForScope($scope));
        }

        // Views (single instance)
        // Resolve from container if possible, ensuring we registered it.
        // But for now, let's keep it simple and register the one we created or get it.
        // Actually, let's stick to using the container for ViewRenderer per plan.
        if ($this->container->has(ViewRenderer::class)) {
            $this->views = $this->container->get(ViewRenderer::class);
        } else {
             // Fallback if dependencies.php isn't loading right?
             $autoEscape = (bool) (\System\Config::get('view.auto_escape') ?? false);
             $this->views = new ViewRenderer([app_path('Views')], '.php', $autoEscape);
        }

        // Initialize legacy static View class for backward compatibility
        if (class_exists(\System\View\View::class)) {
            \System\View\View::setInstance($this->views);
        }
 
        // Controller factory (inject container)
        // Initialize Router
        $this->router->setContainer($this->container);
        $factory = new \System\Core\ControllerFactory($this->container);
        $this->router->setControllerFactory($factory);

        // Database: only initialize if the app has configured it.
        // Yantra does NOT force a DB connection — apps opt in via config/dependencies.php.
        try {
            // The PRIMARY connection is built from config directly (NOT the
            // container's Database::class binding) so an app may rebind
            // Database::class to a request-scoped proxy without breaking the
            // org/legacy connection. Single-DB apps are unaffected: this is
            // simply their one connection, also exposed as 'org'.
            $dbCfg = \System\Config::get('db');
            if (is_array($dbCfg) && $dbCfg !== []) {
                $primary = new \System\Database\Database($dbCfg);
                \System\Database\ConnectionResolver::set($primary);
                \System\Database\ConnectionManager::register('org', static fn() => $primary);
            } elseif ($this->container->has(\System\Database\Database::class)) {
                $db = $this->container->get(\System\Database\Database::class);
                \System\Database\ConnectionResolver::set($db);
                \System\Database\ConnectionManager::register('org', static fn() => $db);
            }
        } catch (\Throwable $e) {
            // No DB configured — that's fine, the app may not need one.
        }

        // Initialize Logger
        if ($this->container->has(\System\Contracts\LoggerInterface::class)) {
            $logger = $this->container->get(\System\Contracts\LoggerInterface::class);
            $this->kernel = new Kernel(
                router: $this->router,
                environment: $this->environment,
                logger: $logger
            );
        } else {
             // Fallback default logger if not in DI
             $this->kernel = new Kernel(
                router: $this->router,
                environment: $this->environment,
                logger: new \System\Core\Logger(self::getBasePath('storage/logs/fallback.log'))
            );
        }
        // Default Middleware Resolver
        // Default Middleware Resolver (from DI)
        $resolver = $this->container->get('middleware.resolver');
        
        $this->router->setMiddlewareResolver($resolver);
        $this->router->enableMiddleware();
        $this->kernel->setMiddlewareResolver($resolver);
        
        // Global middleware: loaded from app config (not hardcoded).
        // App defines middleware in config/middleware.php or via $kernel->setGlobalMiddleware().
        $mwConfigFile = self::getBasePath('config/middleware.php');
        if (is_file($mwConfigFile)) {
            $this->kernel->loadMiddlewareConfig($mwConfigFile);
        }

        // If app defines global middleware in config, use it; otherwise empty (no forced middleware).
        $mwConfig = Config::get('middleware');
        if (is_array($mwConfig) && isset($mwConfig['global']) && is_array($mwConfig['global'])) {
            $this->kernel->setGlobalMiddleware($mwConfig['global']);
        }


        return $this;
    }
    private function initDevErrorLogging(): void
    {
        if ($this->environment !== 'development') {
            return;
        }

        // Resolve log file
        $logFile = self::getBasePath('storage/logs/error.log');
        $logDir  = dirname($logFile);

        // Ensure directory exists
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        // Ensure file exists
        if (!is_file($logFile)) {
            @touch($logFile);
        }

        // Validate writability early (so you don't silently fail)
        if (!is_dir($logDir) || !is_writable($logDir) || (is_file($logFile) && !is_writable($logFile))) {
            // Fallback: at least try PHP default error log
            error_log("YANTRA: Log path not writable: {$logFile}");
            throw new RuntimeException("Log path not writable: {$logFile}");
        }

        // Turn on logging
        ini_set('display_errors', '1');
        ini_set('log_errors', '1');

        // IMPORTANT: ini_set can FAIL if error_log is set via php_admin_value (SYSTEM-level)
        $prev = ini_set('error_log', $logFile);

        // Be explicit in dev
        error_reporting(E_ALL);

        // Write a startup line and verify the outcome
        //$ok = error_log('YANTRA: dev logging initialized -> ' . $logFile);

        // If ini_set was blocked or error_log() failed, write directly as a last resort
        if ($prev === false) {
            @file_put_contents(
                $logFile,
                '[' . date('Y-m-d H:i:s') . '] YANTRA: fallback direct write (ini_set blocked or error_log failed)' . PHP_EOL,
                FILE_APPEND
            );
        }

        // Capture fatals (parse errors, etc.) at shutdown into the same file
        register_shutdown_function(function () use ($logFile) {
            $e = error_get_last();
            if (!$e) return;

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($e['type'] ?? 0, $fatalTypes, true)) return;

            @file_put_contents(
                $logFile,
                '[' . date('Y-m-d H:i:s') . '] FATAL: ' . ($e['message'] ?? '')
                . ' in ' . ($e['file'] ?? '') . ':' . ($e['line'] ?? '') . PHP_EOL,
                FILE_APPEND
            );
        });
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
    /** @var array<string,array<int,string>> */
    private array $routeFiles = [];

    private function detectRouteScope(): string
    {
        $base = (string)(\System\Config::get('app.site') ?? '');

        // If someone accidentally set a full URL, reduce to just its path (defensive)
        if (preg_match('~^https?://~i', $base)) {
            $base = (string)(parse_url($base, PHP_URL_PATH) ?: '');
        }

        // Normalize base to "solar" or "" (no slashes)
        $base = trim($base, '/');

        $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? ltrim($path, '/') : '';
        if ($path === '') $path = '';

        // Build prefix: "" or "solar/"
        $prefix = $base !== '' ? ($base . '/') : '';

        if (str_starts_with($path, $prefix . 'admin')) return 'admin';
        if (str_starts_with($path, $prefix . 'api'))   return 'api';
        return 'web';
    }


    private function cacheDirForScope(string $scope): string
    {
        return self::getBasePath('storage/cache/routes/' . $scope);
    }

    private function loadRoutesCallable(array $files): callable
    {
        return function(\System\Core\Routing\RouteCollector $r) use ($files) {
            foreach ($files as $file) {
                 if (!is_file($file)) continue;
                 $definition = require $file;
                 if (is_callable($definition)) {
                     $definition($r);
                 }
            }
        };
    }

    private function ensureScopeCache(string $scope, bool $force = false): void
    {
        $files = $this->routeFiles[$scope] ?? [];
        if (empty($files)) return;

        $cacheDir = $this->cacheDirForScope($scope);
        $index    = $cacheDir . DIRECTORY_SEPARATOR . '__index.php';

        // Compile if cache missing or forced (dev mode)
        if ($force || !is_file($index)) {
            $router = new Router($cacheDir);
            $router->compileAndCache($this->loadRoutesCallable($files), true);
        }
    }

    public function view(): ViewRenderer
    {
        return $this->views;
    }

    public function router(): Router
    {
        return $this->router;
    }
    
    public function kernel(): Kernel
    {
        return $this->kernel;
    }

    public function setCommandRegistry(CommandRegistry $cli): void
    {
        $this->cli = $cli;
    }

    public function cli(): ?CommandRegistry
    {
        return $this->cli;
    }
}
