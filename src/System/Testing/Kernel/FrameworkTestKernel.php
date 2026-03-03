<?php
declare(strict_types=1);

namespace System\Testing\Kernel;

use System\Core\Application;

class FrameworkTestKernel extends TestKernel
{
    protected function configure(Application $app): void
    {
        // specific setup for framework internals testing
        // e.g. minimal routes, in-memory DB config override if not file-based
        
        // Ensure Application connects to in-memory SQLite if not configured
        // But Database::loadConfig handles defaults.
        // Initialize SessionStore with ArraySessionAdapter to prevent native session start
        if (!\System\Session\SessionStore::is_init()) {
            \System\Session\SessionStore::init(new \System\Testing\Sandbox\ArraySessionAdapter());
        }
    }
}
