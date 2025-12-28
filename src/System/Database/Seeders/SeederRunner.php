<?php
declare(strict_types=1);

namespace System\Database\Seeders;

use PDO;
use RuntimeException;

final class SeederRunner
{
    public function __construct(private PDO $pdo)
    {
    }

    public function run(string $databaseSeederFqcn): void
    {
        if (!class_exists($databaseSeederFqcn)) {
            throw new RuntimeException("Seeder class not found: {$databaseSeederFqcn}");
        }

        $seeder = new $databaseSeederFqcn();

        if (!method_exists($seeder, 'run')) {
            throw new RuntimeException("Seeder must have run(PDO \$pdo): void method: {$databaseSeederFqcn}");
        }

        $seeder->run($this->pdo);
    }
}
