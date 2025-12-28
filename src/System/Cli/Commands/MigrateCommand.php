<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;
use System\Config;
use System\Database\Database;
use System\Database\Migrations\Migrator;

final class MigrateCommand extends AbstractCommand
{
    public function name(): string { return 'migrate'; }

    public function description(): string
    {
        return 'Run pending database migrations (application-owned migrations).';
    }

    public function usage(): array
    {
        return [
            "yantra migrate",
            "yantra migrate --path=database/migrations",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $cfg = (array) Config::get('db');

        $path = $this->getOpt($in, 'path')
            ?? ($cfg['migrations_path'] ?? (defined('BASEPATH') ? BASEPATH . '/database/migrations' : 'database/migrations'));

        try {
            $out->writeln("Using migrations_path: " . $path);
            $migrator = new Migrator(Database::pdo(), (string) $path);
            $result   = $migrator->migrate();

            if ($result->ranCount === 0) {
                $out->writeln(Style::ok("No pending migrations."));
                return 0;
            }

            $out->writeln(Style::ok("Migrations complete.") . " Batch={$result->batch}, Ran={$result->ranCount}");
            foreach ($result->ranMigrations as $m) {
                $out->writeln("  - {$m}");
            }

            return 0;
        } catch (\Throwable $e) {
            $out->error(Style::err("Migration failed: " . $e->getMessage()));
            return 1;
        }
    }

    private function getOpt(Input $in, string $key): ?string
    {
        // Preferred: Input::opt('key') if exists
        if (method_exists($in, 'option')) {
            $v = $in->option($key);
            return $v !== null ? (string) $v : null;
        }

        // Fallback: Input::args() if exists
        if (method_exists($in, 'args')) {
            $args = (array) $in->args();
            $v = $this->parseOpt($args, $key);
            return $v;
        }

        // Final fallback: parse $_SERVER['argv']
        $args = $_SERVER['argv'] ?? [];
        $v = $this->parseOpt((array) $args, $key);
        return $v;
    }

    private function parseOpt(array $argv, string $key): ?string
    {
        $flagEq = '--' . $key . '=';
        $flag   = '--' . $key;

        foreach ($argv as $i => $a) {
            $a = (string) $a;

            if (str_starts_with($a, $flagEq)) {
                $val = substr($a, strlen($flagEq));
                return $val !== '' ? $val : null;
            }

            if ($a === $flag && isset($argv[$i + 1])) {
                $val = (string) $argv[$i + 1];
                return $val !== '' ? $val : null;
            }
        }

        return null;
    }
}
