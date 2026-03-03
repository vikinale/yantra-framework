<?php
declare(strict_types=1);

namespace System\Testing\Kernel;

use System\Testing\Contracts\KernelContract;
use System\Core\Application;
use System\Config;

abstract class TestKernel implements KernelContract
{
    protected ?Application $app = null;

    public function boot(): Application
    {
        if ($this->app === null) {
            $this->app = $this->createApplication();
        }
        return $this->app;
    }

    public function shutdown(): void
    {
        $this->app = null;
    }

    protected function createApplication(): Application
    {
        // Force testing environment
        $_ENV['APP_ENV'] = 'testing';
        putenv('APP_ENV=testing');

        // Initialize Application
        $app = Application::create('testing');
        
        // Ensure config is loaded for testing overrides
        // Assuming Application constructor loads config. 
        // We might want to inject specific test config here if supported.
        
        // Configure for testing (disable real mailers, etc)
        $this->configure($app);
        
        $app->boot();
        
        return $app;
    }

    /**
     * Configure application for testing (mocks, etc.)
     */
    abstract protected function configure(Application $app): void;
}
