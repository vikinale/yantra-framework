<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Config;
use System\Database\Database;
use System\Database\Seeders\SeederRunner;

final class DbSeedCommand
{
    public function name(): string { return 'db:seed'; }

    public function description(): string
    {
        return 'Run application seeders (default data).';
    }

    public function run(array $argv = []): int
    {
        $cfg = (array) Config::get('database');

        // App-owned seeder class (convention-friendly)
        $seederClass = $this->argValue($argv, '--class')
            ?? ($cfg['database_seeder'] ?? 'Database\\Seeders\\DatabaseSeeder');

        $runner = new SeederRunner(Database::pdo());
        $runner->run((string)$seederClass);

        fwrite(STDOUT, "Seeding complete: {$seederClass}\n");
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
