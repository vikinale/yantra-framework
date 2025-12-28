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
