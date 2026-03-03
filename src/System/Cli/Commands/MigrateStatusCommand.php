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

final class MigrateStatusCommand extends AbstractCommand
{
    public function name(): string { return 'migrate:status'; }

    public function description(): string
    {
        return 'Show migration status (ran/pending).';
    }

    public function usage(): array
    {
        return [
            "yantra migrate:status",
            "yantra migrate:status --path=database/migrations",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $cfg = (array) Config::get('db');

        $path = $this->getOpt($in, 'path')
            ?? ($cfg['migrations_path'] ?? (defined('BASEPATH') ? BASEPATH . '/database/migrations' : 'database/migrations'));

        try {
            $migrator = new Migrator(Database::pdo(), (string) $path);
            $rows     = $migrator->status();

            if ($rows === []) {
                $out->writeln(Style::warn("No migration files found in: {$path}"));
                return 0;
            }

            $ran = 0;
            foreach ($rows as $r) {
                if (!empty($r['ran'])) $ran++;
            }

            $out->writeln(Style::bold("Migrations:") . " {$ran}/" . count($rows) . " applied");
            foreach ($rows as $r) {
                $mark = !empty($r['ran']) ? Style::ok('[Y]') : Style::warn('[N]');
                $out->writeln("{$mark} {$r['migration']}");
            }

            return 0;
        } catch (\Throwable $e) {
            $out->error(Style::err("Status failed: " . $e->getMessage()));
            return 1;
        }
    }

}
