<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Config;
use System\Database\Database;
use System\Database\Migrations\Migrator;

final class MigrateCommand
{
    public function name(): string { return 'migrate'; }

    public function description(): string
    {
        return 'Run pending database migrations (application-owned migrations).';
    }

    public function run(array $argv = []): int
    {
        $cfg  = (array) Config::get('database');
        $path = $this->argValue($argv, '--path')
            ?? ($cfg['migrations_path'] ?? (defined('BASEPATH') ? BASEPATH . '/database/migrations' : 'database/migrations'));

        $migrator = new Migrator(Database::pdo(), (string)$path);
        $result   = $migrator->migrate();

        fwrite(STDOUT, "Migrations complete. Batch={$result->batch}, Ran={$result->ranCount}\n");
        foreach ($result->ranMigrations as $m) {
            fwrite(STDOUT, " - {$m}\n");
        }

        return 0;
    }

    private function argValue(array $argv, string $name): ?string
    {
        // Supports: --path=/x OR --path /x
        foreach ($argv as $i => $a) {
            if (str_starts_with($a, $name . '=')) {
                return substr($a, strlen($name) + 1);
            }
            if ($a === $name && isset($argv[$i + 1])) {
                return (string)$argv[$i + 1];
            }
        }
        return null;
    }
}
