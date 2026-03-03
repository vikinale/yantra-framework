<?php
declare(strict_types=1);

namespace System\Testing\Runtime;

use System\Testing\Sandbox\DbSandbox;
use System\Testing\Sandbox\FsSandbox;
use System\Testing\Sandbox\CacheSandbox;
use System\Testing\Sandbox\SessionSandbox;
use System\Testing\Sandbox\ClockFake;
use System\Testing\Http\TestClient;
use System\Core\Application;
use System\Core\Container\Container; // If exists, otherwise Application acts as container

class TestContext
{
    public function __construct(
        private DbSandbox $db,
        private FsSandbox $fs,
        private CacheSandbox $cache,
        private SessionSandbox $session,
        private ClockFake $clock,
        private TestClient $http,
        private Application $app,
        private array $meta = []
    ) {}

    public function db(): DbSandbox { return $this->db; }
    public function fs(): FsSandbox { return $this->fs; }
    public function cache(): CacheSandbox { return $this->cache; }
    public function session(): SessionSandbox { return $this->session; }
    public function clock(): ClockFake { return $this->clock; }
    
    public function http(): TestClient { return $this->http; }
    
    public function container(): Application
    {
        return $this->app;
    }

    public function meta(string $key = null): mixed
    {
        if ($key === null) return $this->meta;
        return $this->meta[$key] ?? null;
    }
}
