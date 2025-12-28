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
use System\Database\Seeders\SeederRunner;

final class MigrateRefreshCommand extends AbstractCommand
{
    public function name(): string { return 'migrate:refresh'; }

    public function description(): string
    {
        return 'DEV ONLY: rollback all migrations, re-run migrate, then seed (optional).';
    }

    public function usage(): array
    {
        return [
            "yantra migrate:refresh",
            "yantra migrate:refresh --no-seed",
            "yantra migrate:refresh --path=database/migrations",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        // Environment guard
        $app = (array) Config::get('app');
        $env = (string) ($app['environment'] ?? 'production');

        if (!in_array($env, ['development', 'local', 'testing'], true)) {
            $out->error(Style::err("Blocked: migrate:refresh is allowed only in development/testing. Current env: {$env}"));
            return 3;
        }

        $cfg  = (array) Config::get('database');
        $path = $this->getOpt($in, 'path')
            ?? ($cfg['migrations_path'] ?? (defined('BASEPATH') ? BASEPATH . '/database/migrations' : 'database/migrations'));

        $noSeed = $this->hasFlag($in, '--no-seed');

        try {
            $pdo = Database::pdo();
            $migrator = new Migrator($pdo, (string) $path);

            $out->writeln(Style::warn("Refreshing database migrations (destructive)."));
            $out->writeln("Path: {$path}");

            // Rollback repeatedly until nothing left
            $totalRolled = 0;
            while (true) {
                $rolled = $migrator->rollback(null);
                if ($rolled === []) break;

                $totalRolled += count($rolled);
                $out->writeln(Style::ok("Rolled back batch:") . " " . count($rolled) . " migration(s)");
            }

            $out->writeln(Style::ok("Rollback complete.") . " Total rolled back: {$totalRolled}");

            // Migrate again
            $result = $migrator->migrate();
            $out->writeln(Style::ok("Migrate complete.") . " Batch={$result->batch}, Ran={$result->ranCount}");
            foreach ($result->ranMigrations as $m) {
                $out->writeln("  - {$m}");
            }

            // Seed (default yes)
            if (!$noSeed) {
                $seederClass = $cfg['database_seeder'] ?? 'Database\\Seeders\\DatabaseSeeder';
                (new SeederRunner($pdo))->run((string) $seederClass);
                $out->writeln(Style::ok("Seed complete: {$seederClass}"));
            } else {
                $out->writeln(Style::warn("Seed skipped (--no-seed)."));
            }

            return 0;
        } catch (\Throwable $e) {
            $out->error(Style::err("Refresh failed: " . $e->getMessage()));
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

    private function hasFlag(Input $in, string $flag): bool
    {
        if (method_exists($in, 'args')) {
            $args = (array) $in->args();
            foreach ($args as $a) {
                if ((string) $a === $flag) return true;
            }
            return false;
        }

        $argv = (array) ($_SERVER['argv'] ?? []);
        foreach ($argv as $a) {
            if ((string) $a === $flag) return true;
        }
        return false;
    }
}
