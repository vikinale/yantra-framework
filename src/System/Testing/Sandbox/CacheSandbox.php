<?php
declare(strict_types=1);

namespace System\Testing\Sandbox;

use System\Utilities\Cache;

class CacheSandbox
{
    private ArrayCacheAdapter $adapter;

    public function __construct()
    {
        $this->adapter = new ArrayCacheAdapter();
    }

    public function init(): void
    {
        Cache::setAdapter($this->adapter);
    }

    public function reset(): void
    {
        $this->adapter->flush();
    }
}
