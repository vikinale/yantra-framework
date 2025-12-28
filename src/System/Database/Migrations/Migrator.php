<?php
declare(strict_types=1);

namespace System\Database\Migrations;

use PDO;
use RuntimeException;

final class Migrator
{
    private MigrationRepository $repo;
    private MigrationLock $lock;

    public function __construct(
        private PDO $pdo,
        private string $migrationsPath
    ) {
        $this->migrationsPath = rtrim($this->migrationsPath, '/\\');
        $this->repo = new MigrationRepository($this->pdo);
        $this->lock = new MigrationLock($this->pdo);
    }

    public function migrate(): MigrationResult
    {
        $this->repo->ensureTable();
        $this->lock->acquire();

        try {
            $ranMap = $this->repo->ranMap();
            $batch  = $this->repo->nextBatch();

            $files = $this->migrationFiles();
            $ran   = [];

            foreach ($files as $file) {
                $name = basename($file);
                if (isset($ranMap[$name])) {
                    continue;
                }

                $migration = $this->loadMigration($file);

                $this->pdo->beginTransaction();
                try {
                    $migration->up($this->pdo);
                    $this->repo->markRan($name, $batch);
                    $this->pdo->commit();
                    $ran[] = $name;
                } catch (\Throwable $e) {
                    $this->pdo->rollBack();
                    throw new RuntimeException("Migration failed: {$name}: {$e->getMessage()}", 0, $e);
                }
            }

            return new MigrationResult(count($ran), $batch, $ran);
        } finally {
            $this->lock->release();
        }
    }

    /**
     * Rollback last batch by default, or a specific batch.
     * @return string[] rolled back migration filenames
     */
    public function rollback(?int $batch = null): array
    {
        $this->repo->ensureTable();
        $this->lock->acquire();

        try {
            $batch ??= $this->repo->lastBatch();
            if ($batch <= 0) return [];

            $migrations = $this->repo->migrationsInBatch($batch);
            if (!$migrations) return [];

            $rolled = [];

            foreach ($migrations as $migrationName) {
                $file = $this->migrationsPath . DIRECTORY_SEPARATOR . $migrationName;
                if (!is_file($file)) {
                    throw new RuntimeException("Cannot rollback: migration file missing: {$migrationName}");
                }

                $migration = $this->loadMigration($file);

                $this->pdo->beginTransaction();
                try {
                    $migration->down($this->pdo);
                    $this->repo->deleteMark($migrationName);
                    $this->pdo->commit();
                    $rolled[] = $migrationName;
                } catch (\Throwable $e) {
                    $this->pdo->rollBack();
                    throw new RuntimeException("Rollback failed: {$migrationName}: {$e->getMessage()}", 0, $e);
                }
            }

            return $rolled;
        } finally {
            $this->lock->release();
        }
    }

    /** @return array<int,array{migration:string, ran:bool}> */
    public function status(): array
    {
        $this->repo->ensureTable();

        $ranMap = $this->repo->ranMap();
        $out = [];

        foreach ($this->migrationFiles() as $file) {
            $name = basename($file);
            $out[] = ['migration' => $name, 'ran' => isset($ranMap[$name])];
        }

        return $out;
    }

    /** @return string[] */
    private function migrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . DIRECTORY_SEPARATOR . '*.php');
        if (!is_array($files)) return [];

        sort($files);
        return $files;
    }

    private function loadMigration(string $file): MigrationInterface
    {
        $obj = require $file;

        if (!$obj instanceof MigrationInterface) {
            throw new RuntimeException(
                "Invalid migration file (must return MigrationInterface): " . basename($file)
            );
        }

        return $obj;
    }
}
