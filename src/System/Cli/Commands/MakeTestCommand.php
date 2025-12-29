<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

final class MakeTestCommand extends AbstractCommand
{
    public function name(): string { return 'make:test'; }

    public function description(): string
    {
        return 'Create a PHPUnit test skeleton (Unit/Feature). Supports interactive mode and DB-backed stubs.';
    }

    public function usage(): array
    {
        return [
            "yantra make:test ContactModelTest",
            "yantra make:test ContactForm --type=feature",
            "yantra make:test ContactModelTest --db",
            "yantra make:test ContactModelTest --db=sqlite",
            "yantra make:test ContactModelTest --db=mysql",
            "yantra make:test MigratorTest --db=migrate",
            "yantra make:test RouterTest --type=unit --db=mysql --force",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $rawName = trim((string)($in->arg(0) ?? ''));

        // Options
        $typeOpt   = strtolower((string)($this->opt($in, 'type') ?? ''));
        $dbModeOpt = $this->dbModeFromOpt($in);
        $forceOpt  = $this->boolOpt($in, 'force');

        // Interactive when no name
        if ($rawName === '') {
            $out->writeln(Style::bold("make:test (interactive)"));
            $out->writeln("Press Enter to accept defaults.");

            $rawName = $this->prompt("Test class name", "ExampleTest");

            $typeOpt = $typeOpt !== '' ? $typeOpt : strtolower($this->prompt("Type (unit|feature)", "unit"));
            if ($typeOpt !== 'feature') $typeOpt = 'unit';

            $dbModeOpt = $dbModeOpt ?? strtolower($this->prompt("DB mode (none|sqlite|mysql|migrate)", "none"));
            $dbModeOpt = $this->normalizeDbMode($dbModeOpt);

            $forceAns = $forceOpt ? 'yes' : strtolower($this->prompt("Overwrite if exists? (yes|no)", "no"));
            $forceOpt = $forceOpt || in_array($forceAns, ['y', 'yes', '1', 'true'], true);
        }

        $name = $this->normalizeTestClassName($rawName);

        if (!$this->isValidPhpIdentifier($name)) {
            $out->error(Style::err("Invalid test name: {$rawName}"));
            $out->writeln("Example: yantra make:test ContactModelTest");
            return 2;
        }

        $type   = ($typeOpt === 'feature') ? 'feature' : 'unit';
        $dbMode = $this->normalizeDbMode($dbModeOpt ?? 'none');
        $force  = $forceOpt;

        $basePath = $this->basePath();
        $dir      = $basePath . '/tests/' . ($type === 'feature' ? 'Feature' : 'Unit');
        $path     = $dir . '/' . $name . '.php';

        if (is_file($path) && !$force) {
            $out->error(Style::err("File exists: {$path}"));
            $out->writeln("Use --force or re-run and choose overwrite in interactive mode.");
            return 3;
        }

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $out->error(Style::err("Failed to create directory: {$dir}"));
            return 1;
        }

        $content = $this->stub($name, $type, $dbMode);

        if (file_put_contents($path, $content) === false) {
            $out->error(Style::err("Failed to write test file: {$path}"));
            return 1;
        }

        $out->writeln(Style::ok("Created: ") . $path);
        $out->writeln("Run: vendor/bin/phpunit");
        return 0;
    }

    private function prompt(string $label, string $default): string
    {
        fwrite(STDOUT, "{$label} [{$default}]: ");
        $line = fgets(STDIN);
        if ($line === false) return $default;
        $line = trim($line);
        return $line === '' ? $default : $line;
    }

    private function basePath(): string
    {
        if (defined('BASEPATH') && is_string(BASEPATH) && BASEPATH !== '') {
            return rtrim(BASEPATH, "/\\");
        }
        return rtrim((string)getcwd(), "/\\");
    }

    private function normalizeTestClassName(string $name): string
    {
        $name = preg_replace('/\.php$/i', '', $name) ?? $name;
        if (!str_ends_with($name, 'Test')) $name .= 'Test';
        return $name;
    }

    private function isValidPhpIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
    }

    /**
     * --db supports:
     *   (absent) => none
     *   --db     => sqlite
     *   --db=sqlite|mysql|migrate|none
     */
    private function dbModeFromOpt(Input $in): ?string
    {
        $raw = $this->opt($in, 'db');
        if ($raw === null) return null;

        $raw = strtolower(trim((string)$raw));
        // boolean flag => '1' => default sqlite
        if ($raw === '1' || $raw === 'true' || $raw === 'yes' || $raw === '') {
            return 'sqlite';
        }
        return $raw;
    }

    private function normalizeDbMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === '' || $mode === '0' || $mode === 'false' || $mode === 'no' || $mode === 'off') {
            return 'none';
        }

        return match ($mode) {
            'sqlite'  => 'sqlite',
            'mysql'   => 'mysql',
            'migrate' => 'migrate',
            'none'    => 'none',
            default   => 'none',
        };
    }

    private function stub(string $className, string $type, string $dbMode): string
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
    public function itWorks(): void
    {
{$dbBlock}
        self::assertTrue(true);
    }
}

PHP;
    }

    private function dbBlock(string $dbMode): string
    {
        // Keep generated tests self-contained and PHPUnit 11 compatible.
        // - sqlite: uses in-memory PDO
        // - mysql:  uses framework Database::pdo() (requires app bootstrap loads config)
        // - migrate: runs migrations from tests/fixtures/migrations into sqlite memory
        return match ($dbMode) {
            'sqlite' => <<<PHP

        // DB: SQLite in-memory
        \$pdo = new \\PDO('sqlite::memory:');
        \$pdo->setAttribute(\\PDO::ATTR_ERRMODE, \\PDO::ERRMODE_EXCEPTION);

        // Example: create a temp table
        \$pdo->exec("CREATE TABLE demo (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT);");

        \$count = (int)\$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='demo'")
            ->fetchColumn();
        self::assertSame(1, \$count);

PHP,
            'mysql' => <<<PHP

        // DB: MySQL via framework Database::pdo() (uses app config)
        // Ensure your tests bootstrap defines BASEPATH and initializes System\\Config app path.
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

        // Use your framework Migrator (SQLite-compatible fixtures recommended)
        \$migrator = new \\System\\Database\\Migrations\\Migrator(\$pdo, \$migrationsDir);
        \$migrator->migrate();

        // Example: assert yt_migrations exists
        \$count = (int)\$pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='yt_migrations'"
        )->fetchColumn();

        self::assertSame(1, \$count);

PHP,
            default => '',
        };
    }

    /**
     * Option parser compatible with minimal Input implementations.
     * Supports:
     *   --type=feature
     *   --type feature
     *   --db (boolean)
     *   --db=sqlite|mysql|migrate|none
     *   --force (boolean)
     */
    private function opt(Input $in, string $key): ?string
    {
        if (method_exists($in, 'option')) {
            $v = $in->option($key);
            if ($v === true) return '1';
            if ($v === false) return null;
            return $v !== null ? (string)$v : null;
        }

        $argv = (array)($_SERVER['argv'] ?? []);
        $flagEq = '--' . $key . '=';
        $flag   = '--' . $key;

        foreach ($argv as $i => $a) {
            $a = (string)$a;

            if ($a === $flag) return '1';
            if (str_starts_with($a, $flagEq)) return substr($a, strlen($flagEq));

            if ($a === $flag && isset($argv[$i + 1])) {
                return (string)$argv[$i + 1];
            }
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
