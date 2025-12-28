<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Config;
use System\Database\Database;
use System\Database\Migrations\Migrator;

final class MigrateStatusCommand
{
    public function name(): string { return 'migrate:status'; }

    public function description(): string
    {
        return 'Show migration status (ran/pending).';
    }

    public function run(array $argv = []): int
    {
        $cfg  = (array) Config::get('database');
        $path = $this->argValue($argv, '--path')
            ?? ($cfg['migrations_path'] ?? (defined('BASEPATH') ? BASEPATH . '/database/migrations' : 'database/migrations'));

        $migrator = new Migrator(Database::pdo(), (string)$path);
        $rows     = $migrator->status();

        foreach ($rows as $r) {
            fwrite(STDOUT, sprintf("[%s] %s\n", $r['ran'] ? 'Y' : 'N', $r['migration']));
        }

        return 0;
    }

    private function argValue(array $argv, string $name): ?string
    {
        foreach ($argv as $i => $a) {
            if (str_starts_with($a, $name . '=')) return substr($a, strlen($name) + 1);
            if ($a === $name && isset($argv[$i + 1])) return (string)$argv[$i + 1];
        }
        return null;
    }
}
