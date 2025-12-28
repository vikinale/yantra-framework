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

final class MigrateRollbackCommand extends AbstractCommand
{
    public function name(): string { return 'migrate:rollback'; }

    public function description(): string
    {
        return 'Rollback last migration batch (or a specific batch).';
    }

    public function usage(): array
    {
        return [
            "yantra migrate:rollback",
            "yantra migrate:rollback --batch=2",
            "yantra migrate:rollback --path=database/migrations --batch=2",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $cfg = (array) Config::get('database');

        $path = $this->getOpt($in, 'path')
            ?? ($cfg['migrations_path'] ?? (defined('BASEPATH') ? BASEPATH . '/database/migrations' : 'database/migrations'));

        $batchStr = $this->getOpt($in, 'batch');
        $batch    = $batchStr !== null ? (int) $batchStr : null;

        try {
            $migrator = new Migrator(Database::pdo(), (string) $path);
            $rolled   = $migrator->rollback($batch);

            if ($rolled === []) {
                $out->writeln(Style::warn("Nothing to rollback."));
                return 0;
            }

            $out->writeln(Style::ok("Rollback complete.") . " RolledBack=" . count($rolled));
            foreach ($rolled as $m) {
                $out->writeln("  - {$m}");
            }

            return 0;
        } catch (\Throwable $e) {
            $out->error(Style::err("Rollback failed: " . $e->getMessage()));
            return 1;
        }
    }

    private function getOpt(Input $in, string $key): ?string
    {
        if (method_exists($in, 'option')) {
            $v = $in->option($key);
            return $v !== null ? (string) $v : null;
        }
        if (method_exists($in, 'args')) {
            return $this->parseOpt((array) $in->args(), $key);
        }
        return $this->parseOpt((array) ($_SERVER['argv'] ?? []), $key);
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
