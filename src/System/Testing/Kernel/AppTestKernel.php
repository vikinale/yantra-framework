<?php
declare(strict_types=1);

namespace System\Testing\Kernel;

use System\Core\Application;

class AppTestKernel extends TestKernel
{
    protected function configure(Application $app): void
    {
        // Load app routes? Application::boot() usually loads them based on environment.
        // If testing mode needs specific route loading, do it here.
        
        // Maybe load 'routes/web.php' explicitly if Application doesn't auto-load in 'testing' env?
        // Application logic:
        // if ($this->environment === 'development') ...
        // Real boot loads routes from storage/cache/routes usually.
        // We might need to ensure routes are active.
        
        // Initialize SessionStore with ArraySessionAdapter to prevent native session execution
        if (!\System\Session\SessionStore::is_init()) {
            \System\Session\SessionStore::init(new \System\Testing\Sandbox\ArraySessionAdapter());
        }
        
        $app->initRoutes(['web' => app_path('routes/web.php')]); // hypothetical
    }
}
