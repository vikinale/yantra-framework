<?php

use System\Contracts\ContainerInterface;
use System\Config;
use System\Contracts\ConfigInterface;
use System\Contracts\DatabaseInterface;
use System\Contracts\HooksInterface;
use System\Contracts\SessionInterface;
use System\Core\Routing\Router;
use System\Database\Database;
use System\Hooks;
use System\Session\SessionStore;
use System\View\ViewRenderer;
use System\Core\Application;

return [
    // Configuration
    'config.view.auto_escape' => function () {
        return (bool) (Config::get('view.auto_escape') ?? false);
    },
    'config.app.routes_cache' => function () {
        // Dynamic path based on environment/scope - Application handles this logic for now,
        // but we can default to something safe or inject it later.
        // For Router, we just need a base cache dir.
        return Application::getBasePath('storage/cache/routes');
    },

    // Core Services
    ViewRenderer::class => function (ContainerInterface $c) {
        return new ViewRenderer(
            [app_path('Views')], 
            '.php', 
            $c->get('config.view.auto_escape')
        );
    },

    // Router factory - needs scope-aware cache dir. 
    // Since Router is scope-dependent (web/api/admin), we might need a factory that
    // takes the scope. However, Application controls the scope detection.
    // For now, let's keep Router creation inside Application but allow injection of dependencies if needed.
    // OR, we can define a default Router here.
    Router::class => function (ContainerInterface $c) {
        // Default router (useful for CLI or testing without specific scope)
        $cacheDir = Application::getBasePath('storage/cache/routes/web');
        return new Router($cacheDir);
    },

    // Database Singleton wrapper
    // Database
    Database::class => function (ContainerInterface $c) {
        // In a full DI world, we would inject config here.
        // For now, Database constructor handles config loading if null passed,
        // or we can pass it from Config::get('db').
        $config = Config::get('db');
        return new Database($config); 
    },

    // Logger
    \System\Contracts\LoggerInterface::class => function () {
        $logFile = Application::getBasePath('storage/logs/app.log');
        return new \System\Core\Logger($logFile);
    },

    // Security middleware requiring explicit configuration
    \System\Security\Middleware\JwtAuthMiddleware::class => function () {
        $publicKeyPem = (string)(Config::get('security.jwt.public_key_pem') ?? '');

        if (trim($publicKeyPem) === '') {
            $keyPath = (string)(Config::get('security.jwt.public_key_path') ?? '');
            if ($keyPath === '') {
                $fromEnvPath = getenv('JWT_PUBLIC_KEY_PATH');
                $keyPath = $fromEnvPath !== false ? (string)$fromEnvPath : '';
            }

            if ($keyPath !== '' && is_file($keyPath) && is_readable($keyPath)) {
                $loaded = file_get_contents($keyPath);
                if (is_string($loaded)) {
                    $publicKeyPem = $loaded;
                }
            }
        }

        if (trim($publicKeyPem) === '') {
            $fromEnvPem = getenv('JWT_PUBLIC_KEY_PEM');
            if ($fromEnvPem !== false) {
                $publicKeyPem = (string)$fromEnvPem;
            }
        }

        $publicKeyPem = trim($publicKeyPem);
        if ($publicKeyPem === '') {
            throw new \RuntimeException(
                'JWT middleware requires a public key. Set security.jwt.public_key_pem, security.jwt.public_key_path, JWT_PUBLIC_KEY_PEM, or JWT_PUBLIC_KEY_PATH.'
            );
        }

        $requiredRoles = Config::get('security.jwt.required_roles') ?? [];
        if (!is_array($requiredRoles)) {
            $requiredRoles = [];
        }

        $leeway = (int)(Config::get('security.jwt.leeway_seconds') ?? 30);
        return new \System\Security\Middleware\JwtAuthMiddleware($publicKeyPem, $requiredRoles, max(0, $leeway));
    },

    // Middleware Resolver
    'middleware.resolver' => function (ContainerInterface $c) {
        $map = [
            'sec.normalize' => \System\Security\Middleware\RequestNormalizationMiddleware::class,
            'sec.cookies'   => \System\Security\Middleware\CookieHardeningMiddleware::class,
            'sec.audit'     => \System\Security\Middleware\AuditMiddleware::class,
            'sec.csrf'      => \System\Security\Middleware\CsrfMiddleware::class,
            'sec.headers'   => \System\Security\Middleware\SecurityHeadersMiddleware::class,
            'sec.csp'       => \System\Security\Middleware\CspNonceMiddleware::class,
            'auth.jwt'      => \System\Security\Middleware\JwtAuthMiddleware::class,
            'rate.limit'    => \System\Security\Middleware\RateLimitMiddleware::class,
        ];

        return function (string $id) use ($map, $c) {
            // 1. Mapped Middleware
            if (isset($map[$id])) {
                $class = $map[$id];
                $instance = $c->get($class);
                if (is_callable($instance)) {
                    return $instance;
                }
                throw new \RuntimeException("Middleware is not callable: {$class}");
            }

            // 2. Class Name (FQCN)
            if (class_exists($id)) {
                $instance = $c->get($id);
                if (is_callable($instance)) {
                    return $instance;
                }
                throw new \RuntimeException("Middleware class is not callable: {$id}");
            }

            throw new \RuntimeException("Middleware not found: {$id}");
        };
    },

    /* =====================================================
     | Phase 5: Interface-based abstractions
     | These allow consumers to type-hint interfaces via DI.
     | Note: Application also registers these dynamically
     | after container build; these serve as fallback definitions.
     ===================================================== */

    ConfigInterface::class => function () {
        return Config::getInstance();
    },

    DatabaseInterface::class => function () {
        return Database::getInstance()->getAdapter();
    },

    HooksInterface::class => function () {
        return Hooks::getInstance();
    },

    SessionInterface::class => function () {
        return SessionStore::getInstance();
    },
];
