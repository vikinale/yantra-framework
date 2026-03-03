<?php
declare(strict_types=1);

namespace System\Testing\Contracts;

use System\Testing\Runtime\TestContext;
use System\Testing\Data\DataSet;
use System\Testing\Runtime\SkipTest;
use System\Testing\Contracts\KernelContract;
use System\Testing\Sandbox\DbSandbox;
use System\Testing\Sandbox\FsSandbox;
use System\Testing\Sandbox\CacheSandbox;
use System\Testing\Sandbox\SessionSandbox;
use System\Testing\Sandbox\ClockFake;
use System\Testing\Http\TestClient;
use System\Core\Application;
use Throwable;

/**
 * Abstract base class for all Yantra tests.
 * Adapts Yantra's data-driven structure to PHPUnit.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected ?KernelContract $kernelInstance = null;
    protected ?Application $app = null;

    protected ?DbSandbox $db = null;
    protected ?FsSandbox $fs = null;
    protected ?CacheSandbox $cache = null;
    protected ?SessionSandbox $session = null;
    protected ?ClockFake $clock = null;

    /**
     * Return the human-readable name of this test suite.
     */
    abstract public static function suiteName(): string;

    /**
     * Return the kernel class to use for this test.
     * Defaults to AppTestKernel.
     */
    public static function kernel(): string
    {
        return \System\Testing\Kernel\AppTestKernel::class;
    }

    /**
     * Return the dataset for this test.
     * 
     * @return DataSet[]
     */
    abstract public static function dataset(): array;

    /**
     * Prepare the test environment and data.
     */
    protected function arrange(TestContext $ctx, array $row): void 
    {
        // Optional override
    }

    /**
     * Execute the action under test.
     * 
     * @return mixed Result to be passed to assert
     */
    abstract protected function act(TestContext $ctx, array $row): mixed;

    /**
     * Verify the results.
     */
    abstract protected function assert(TestContext $ctx, array $row, mixed $result): void;

    /**
     * Run once before any test cases in this class.
     */
    public function beforeAll(TestContext $ctx): void 
    {
        // Optional hook
    }

    /**
     * Run once after all test cases in this class.
     */
    public function afterAll(TestContext $ctx): void 
    {
        // Optional hook
    }

    /**
     * Run before each test case (row).
     */
    public function beforeEach(TestContext $ctx, array $row): void 
    {
        // Optional hook
    }

    /**
     * Run after each test case (row).
     */
    public function afterEach(TestContext $ctx, array $row): void 
    {
        // Optional hook
    }


    /**
     * PHPUnit SetUp
     */
    protected function setUp(): void
    {
        parent::setUp();

        $kernelClass = static::kernel();
        $this->kernelInstance = new $kernelClass();
        $this->app = $this->kernelInstance->boot();

        $this->db = new DbSandbox();
        $this->fs = new FsSandbox(); // We will init inside runCase
        $this->cache = new CacheSandbox();
        $this->session = new SessionSandbox();
        $this->clock = new ClockFake();
        
        // BeforeAll hook simulation? 
        // PHPUnit has `setUpBeforeClass`, but it's static. 
        // Yantra's beforeAll takes a TestContext with access to App.
        // We can't easily map static `setUpBeforeClass` to instance-based `beforeAll` with dependencies.
        // For now, we will skip `beforeAll` or implement it via a static flag + first test run check, 
        // but that's complex. Most tests use `arrange` anyway.
        // Let's defer strict beforeAll/afterAll compliance unless needed.
    }

    protected function tearDown(): void
    {
        if ($this->kernelInstance) {
            $this->kernelInstance->shutdown();
        }
        parent::tearDown();
    }

    /**
     * Data Provider for PHPUnit
     */
    public static function provideData(): array
    {
        $rows = [];
        $datasets = static::dataset();
        foreach ($datasets as $ds) {
            if ($ds instanceof DataSet) {
                foreach ($ds->getRows() as $row) {
                    $key = ($row['case_id'] ?? 'Unknown') . ': ' . ($row['title'] ?? '');
                    $rows[$key] = [$row];
                }
            }
        }
        return $rows;
    }

    /**
     * The actual test runner method called by PHPUnit for each data row.
     * 
     * @dataProvider provideData
     */
    public function testRunner(array $row): void
    {
        if (!empty($row['skip'])) {
            $this->markTestSkipped($row['skip']);
        }

        // Per-case Sandbox Init
        $this->fs->init();
        
        $ctx = $this->createContext();

        try {
            $this->db->begin();
            $this->session->init();
            $this->cache->init();
            $this->clock->reset();

            $this->beforeEach($ctx, $row);
            $this->arrange($ctx, $row);
            $result = $this->act($ctx, $row);
            $this->assert($ctx, $row, $result);

        } finally {
            $this->afterEach($ctx, $row);
            
            $this->db->rollback();
            $this->session->reset();
            $this->cache->reset();
            $this->fs->cleanup();
        }
    }

    private function createContext(): TestContext
    {
        // Extract Kernel from App via Reflection
        $kernel = null;
        $ref = new \ReflectionClass($this->app);
        if ($ref->hasProperty('kernel')) {
             $p = $ref->getProperty('kernel');
             $p->setAccessible(true);
             if (!$p->isInitialized($this->app)) {
                 $this->app->boot();
             }
             $kernel = $p->getValue($this->app);
        } else {
            throw new \RuntimeException("Application does not have a kernel property.");
        }

        $client = new TestClient($kernel);

        return new TestContext(
            $this->db, 
            $this->fs, 
            $this->cache, 
            $this->session, 
            $this->clock, 
            $client, 
            $this->app
        );
    }
}
