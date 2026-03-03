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
        $cfg = (array) Config::get('db');

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

}
