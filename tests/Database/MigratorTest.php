<?php
declare(strict_types=1);

namespace Tests\Database;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use System\Database\Migrations\Migrator;

final class MigratorTest extends TestCase
{
    #[Test]
    public function migrationTableIsCreated(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $migrator = new Migrator($pdo, __DIR__ . '/../fixtures/migrations');
        $migrator->migrate();

        $count = (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='yt_migrations'"
        )->fetchColumn();

        self::assertSame(1, $count, 'Expected yt_migrations table to be created.');
    }

    #[Test]
    public function fixtureMigrationRuns(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $migrator = new Migrator($pdo, __DIR__ . '/../fixtures/migrations');
        $migrator->migrate();

        $demo = (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='demo'"
        )->fetchColumn();

        self::assertSame(1, $demo, 'Expected demo fixture table to be created by migration.');
    }
}
