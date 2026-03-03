<?php
declare(strict_types=1);

namespace System\Testing\Runner;

use System\Testing\Contracts\TestCase;
use System\Testing\Data\DataSet;
use System\Testing\Runtime\TestContext;
use System\Testing\Runtime\SkipTest;
use System\Testing\Http\TestClient;
use System\Testing\Sandbox\DbSandbox;
use System\Testing\Sandbox\FsSandbox;
use System\Testing\Sandbox\CacheSandbox;
use System\Testing\Sandbox\SessionSandbox;
use System\Testing\Sandbox\ClockFake;
use Throwable;
use System\Testing\Contracts\KernelContract;
use System\Core\Application;

class Runner
{
    private Discovery $discovery;
    private ConsolePrinter $printer;
    private ReporterCsv $reporter;

    public function __construct()
    {
        $this->discovery = new Discovery();
        $this->printer = new ConsolePrinter();
    }

    public function run(array $paths, string $reportPath): void
    {
        $this->reporter = new ReporterCsv($reportPath);
        $runId = uniqid('RUN_', true);
        $startTime = microtime(true);
        $this->printer->info("Starting test run: {$runId}");

        $classes = [];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $classes = array_merge($classes, $this->discovery->findTests($path));
            } elseif (is_file($path)) {
                // simple resolution if path provided directly? 
                // Discovery handles recursive dir. For file we need logic.
                // Assuming paths are directories for now or handled by Discovery if we expand it.
                // Or if user passes a file, we can try to autoload it.
                // Let's rely on Discovery for directories and maybe implement single file later if needed by specs.
                // Spec say: "Directory scan for *Test.php (optional)".
            }
        }
        $classes = array_unique($classes);

        $this->printer->info("Found " . count($classes) . " test classes.");

        foreach ($classes as $class) {
            $this->runClass($runId, $class);
        }

        $duration = microtime(true) - $startTime;
        $this->printer->info("Total time: " . number_format($duration, 4) . "s");
        $this->reporter->close();
    }

    private function runClass(string $runId, string $class): void
    {
        $this->printer->info("Running: {$class}");
        
        /** @var TestCase $testInstance */
        $testInstance = new $class();
        
        $kernelClass = $class::kernel();
        
        /** @var KernelContract $kernel */
        $kernel = new $kernelClass();
        
        try {
            $app = $kernel->boot();
        } catch (Throwable $e) {
            $this->printer->error("Failed to boot kernel for {$class}: " . $e->getMessage());
            echo $e->getTraceAsString();
            return;
        }

        // Initialize reusable sandboxes (if intended to optionally reuse)
        // Spec: "Boot kernel/container once per test class". "Seed base fixtures once per suite".
        // "Transaction-per-case cleanup".
        
        // We instantiate sandboxes here. FsSandbox needs new per case.
        // DbSandbox connects to the app DB.
        
        $db = new DbSandbox();
        $cache = new CacheSandbox();
        $session = new SessionSandbox();
        $clock = new ClockFake();

        // beforeAll
        try {
            // We need a context for beforeAll? 
            // TestCase::beforeAll(TestContext $ctx). 
            // We can create a "BootContext" or just a regular context with no per-case isolation yet?
            // Let's create a temporary context for lifecycle.
            $preCtx = $this->createContext($app, $db, new FsSandbox(), $cache, $session, $clock); 
            $testInstance->beforeAll($preCtx);
        } catch (Throwable $e) {
             $this->printer->error("beforeAll failed: " . $e->getMessage());
             $kernel->shutdown();
             return;
        }

        $datasets = $class::dataset();
        // Flatten datasets
        // dataset() returns DataSet[]
        
        $rows = [];
        foreach ($datasets as $ds) {
            if ($ds instanceof DataSet) {
                foreach ($ds->getRows() as $row) {
                    $rows[] = $row;
                }
            }
        }

        if (empty($rows)) {
            $this->printer->error("No test cases found for {$class}. Check dataset().");
        } else {
             $this->printer->info("Running " . count($rows) . " cases for {$class}");
        }

        foreach ($rows as $row) {
            $this->_runCase($runId, $class, $testInstance, $app, $db, $cache, $session, $clock, $row);
        }

        // afterAll
        try {
            $postCtx = $this->createContext($app, $db, new FsSandbox(), $cache, $session, $clock);
            $testInstance->afterAll($postCtx);
        } catch (Throwable $e) {
             $this->printer->error("afterAll failed: " . $e->getMessage());
        }

        $kernel->shutdown();
    }

    private function _runCase(
        string $runId, 
        string $class,
        TestCase $test, 
        Application $app,
        DbSandbox $db,
        CacheSandbox $cache,
        SessionSandbox $session,
        ClockFake $clock,
        array $row
    ): void {
        $caseId = $row['case_id'] ?? 'Unknown';
        $title = $row['title'] ?? '';
        $meta = $row['meta'] ?? [];
        
        $start = microtime(true);
        $status = 'PASS';
        $error = null;

        // Per-case isolation
        $fs = new FsSandbox();
        $fs->init();
        
        // Context
        $ctx = $this->createContext($app, $db, $fs, $cache, $session, $clock);

        try {
            // Isolation start
            $db->begin();
            $session->init(); 
            $cache->init(); 
            // Reset clock if needed? 
            $clock->reset();

            $test->runCase($ctx, $row);

        } catch (SkipTest $s) {
            $status = 'SKIP';
            $error = $s->getMessage();
        } catch (Throwable $e) {
            $status = 'FAIL';
            $error = $e->getMessage();
            // $this->printer->error("  [{$caseId}] FAIL: {$error}");
        } finally {
            // Isolation end
            $db->rollback();
            $session->reset();
            $cache->reset();
            $fs->cleanup();
        }

        $duration = (microtime(true) - $start) * 1000;
        
        $this->reporter->log($runId, $class, $class::name(), $caseId, $title, $status, $duration, $error, $meta ?: []);
        
        $color = match($status) {
            'PASS' => "\033[32m.\033[0m",
            'FAIL' => "\033[31mF\033[0m",
            'SKIP' => "\033[33mS\033[0m",
        };
        echo $color;

        if ($status === 'FAIL') {
             $this->printer->error("\n  {$caseId}: {$title} -> {$error}");
        }
    }

    private function createContext(
        Application $app,
        DbSandbox $db,
        FsSandbox $fs,
        CacheSandbox $cache,
        SessionSandbox $session,
        ClockFake $clock
    ): TestContext {
        // App's Kernel usually handles the request.
        // We get it from app. But Application has private kernel. 
        // We can expose it or implicitly use Application to dispatch via run() logic 
        // OR TestClient usually needs a dispatcher.
        // Application implements the logic. But TestClient expects specialized Kernel?
        // TestClient constructor takes System\Core\Kernel.
        // We need to extract kernel from Application.
        // Application.php: private Kernel $kernel;
        // Access via reflection since it's private and no getter (assuming based on Application.php view).
        // Wait, Application.php doesn't have getKernel().
        // We will use Reflection to get it.
        
        $ref = new \ReflectionClass($app);
        if ($ref->hasProperty('kernel')) {
             $p = $ref->getProperty('kernel');
             $p->setAccessible(true);
             if (!$p->isInitialized($app)) {
                 // Force boot if not waiting?
                 $app->boot();
             }
             $kernel = $p->getValue($app);
        } else {
            throw new \RuntimeException("Application does not have a kernel property.");
        }

        $client = new TestClient($kernel);
        // Inject session into client if needed? Client uses SessionStore directly.
        
        return new TestContext($db, $fs, $cache, $session, $clock, $client, $app);
    }
}
