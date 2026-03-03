<?php
declare(strict_types=1);

namespace System\Testing\Contracts;

interface KernelContract
{
    /**
     * Boot the application kernel for testing.
     * 
     * @return mixed The application instance or container
     */
    public function boot(): mixed;

    /**
     * Shutdown the kernel and cleanup resources.
     */
    public function shutdown(): void;
}
