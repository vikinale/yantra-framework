<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

/**
 * make:model:test ContactModel
 * Shortcut around make:test conventions.
 */
final class MakeModelTestCommand extends AbstractCommand
{
    public function name(): string { return 'make:model:test'; }

    public function description(): string
    {
        return 'Create a PHPUnit test skeleton for a Model (shortcut of make:test).';
    }

    public function usage(): array
    {
        return [
            "yantra make:model:test ContactModel",
            "yantra make:model:test Contact --db",
            "yantra make:model:test Contact --db=mysql",
            "yantra make:model:test Contact --db=migrate",
            "yantra make:model:test ContactModel --type=feature --force",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $model = trim((string)($in->arg(0) ?? ''));

        if ($model === '') {
            $out->writeln(Style::bold("Usage:"));
            foreach ($this->usage() as $u) $out->writeln("  {$u}");
            return 0;
        }

        if (!str_ends_with($model, 'Model')) $model .= 'Model';
        $testName = $model . 'Test';

        $typeOpt   = strtolower((string)($this->opt($in, 'type') ?? 'unit'));
        $type      = ($typeOpt === 'feature') ? 'feature' : 'unit';

        $dbModeOpt = $this->dbModeFromOpt($in);
        $dbMode    = $this->normalizeDbMode($dbModeOpt ?? 'none');

        $force     = $this->boolOpt($in, 'force');

        $basePath = $this->basePath();
        $dir      = $basePath . '/tests/' . ($type === 'feature' ? 'Feature' : 'Unit');
        $path     = $dir . '/' . $testName . '.php';

        if (is_file($path) && !$force) {
            $out->error(Style::err("File exists: {$path}"));
            $out->writeln("Use --force to overwrite.");
            return 3;
        }

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $out->error(Style::err("Failed to create directory: {$dir}"));
            return 1;
        }

        $content = $this->stubModelTest($testName, $type, $dbMode, $model);

        if (file_put_contents($path, $content) === false) {
            $out->error(Style::err("Failed to write test file: {$path}"));
            return 1;
        }

        $out->writeln(Style::ok("Created: ") . $path);
        return 0;
    }

    private function stubModelTest(string $className, string $type, string $dbMode, string $modelClass): string
    {
        $ns = $type === 'feature' ? 'Tests\\Feature' : 'Tests\\Unit';

        $dbBlock = '';
        if ($dbMode !== 'none') {
            $dbBlock = $this->dbBlock($dbMode);
        }

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns};

use PHPUnit\\Framework\\Attributes\\Test;
use PHPUnit\\Framework\\TestCase;

final class {$className} extends TestCase
{
    #[Test]
    public function modelTestSkeleton(): void
    {
{$dbBlock}
        // TODO: instantiate and test {$modelClass} behavior
        self::assertTrue(true);
    }
}

PHP;
    }

    private function dbBlock(string $dbMode): string
    {
        return match ($dbMode) {
            'sqlite' => <<<PHP

        // DB: SQLite in-memory
        \$pdo = new \\PDO('sqlite::memory:');
        \$pdo->setAttribute(\\PDO::ATTR_ERRMODE, \\PDO::ERRMODE_EXCEPTION);

PHP,
            'mysql' => <<<PHP

        // DB: MySQL via framework Database::pdo() (uses app config)
        \$pdo = \\System\\Database\\Database::pdo();
        self::assertSame('mysql', (string)\$pdo->getAttribute(\\PDO::ATTR_DRIVER_NAME));

PHP,
            'migrate' => <<<PHP

        // DB: SQLite in-memory + run migration fixtures
        \$pdo = new \\PDO('sqlite::memory:');
        \$pdo->setAttribute(\\PDO::ATTR_ERRMODE, \\PDO::ERRMODE_EXCEPTION);

        \$migrationsDir = dirname(__DIR__, 2) . '/fixtures/migrations';
        if (!is_dir(\$migrationsDir)) {
            self::fail("Missing migrations fixtures directory: {\$migrationsDir}");
        }

        \$migrator = new \\System\\Database\\Migrations\\Migrator(\$pdo, \$migrationsDir);
        \$migrator->migrate();

PHP,
            default => '',
        };
    }

    private function basePath(): string
    {
        if (defined('BASEPATH') && is_string(BASEPATH) && BASEPATH !== '') {
            return rtrim(BASEPATH, "/\\");
        }
        return rtrim((string)getcwd(), "/\\");
    }

    private function dbModeFromOpt(Input $in): ?string
    {
        $raw = $this->opt($in, 'db');
        if ($raw === null) return null;

        $raw = strtolower(trim((string)$raw));
        if ($raw === '1' || $raw === 'true' || $raw === 'yes' || $raw === '') {
            return 'sqlite';
        }
        return $raw;
    }

    private function normalizeDbMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === '' || in_array($mode, ['0','false','no','off'], true)) return 'none';

        return match ($mode) {
            'sqlite'  => 'sqlite',
            'mysql'   => 'mysql',
            'migrate' => 'migrate',
            'none'    => 'none',
            default   => 'none',
        };
    }

    private function opt(Input $in, string $key): ?string
    {
        if (method_exists($in, 'option')) {
            $v = $in->option($key);
            if ($v === true) return '1';
            if ($v === false) return null;
            return $v !== null ? (string)$v : null;
        }

        foreach ((array)($_SERVER['argv'] ?? []) as $a) {
            $a = (string)$a;
            if ($a === "--{$key}") return '1';
            if (str_starts_with($a, "--{$key}=")) return substr($a, strlen("--{$key}="));
        }
        return null;
    }

    private function boolOpt(Input $in, string $key): bool
    {
        $v = $this->opt($in, $key);
        if ($v === null) return false;
        $v = strtolower(trim((string)$v));
        return !in_array($v, ['0', 'false', 'no', 'off'], true);
    }
}
