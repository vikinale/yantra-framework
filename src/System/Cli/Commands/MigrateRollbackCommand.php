<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Config;
use System\Database\Database;
use System\Database\Migrations\Migrator;

final class MigrateRollbackCommand
{
    public function name(): string { return 'migrate:rollback'; }

    public function description(): string
    {
        return 'Rollback last migration batch (or a specific batch via --batch=).';
    }

    public function run(array $argv = []): int
    {
        $cfg  = (array) Config::get('database');
        $path = $this->argValue($argv, '--path')
            ?? ($cfg['migrations_path'] ?? (defined('BASEPATH') ? BASEPATH . '/database/migrations' : 'database/migrations'));

        $batchStr = $this->argValue($argv, '--batch');
        $batch    = $batchStr !== null ? (int)$batchStr : null;

        $migrator = new Migrator(Database::pdo(), (string)$path);
        $rolled   = $migrator->rollback($batch);

        fwrite(STDOUT, "Rollback complete. RolledBack=" . count($rolled) . "\n");
        foreach ($rolled as $m) {
            fwrite(STDOUT, " - {$m}\n");
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
