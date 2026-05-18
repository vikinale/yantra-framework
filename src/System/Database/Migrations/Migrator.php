<?php
declare(strict_types=1);

namespace System\Database\Migrations;

use RuntimeException;
use System\Contracts\MigrationInterface;
use System\Database\Database;

final class Migrator
{
    private MigrationRepository $repo;
    private MigrationLock $lock;

    /** @var string[] */
    private array $paths = [];

    /**
     * @param string[]|null $only When provided, only migration files whose
     *        basename appears in this allowlist are run/rolled back/listed.
     *        Used by the scoped multi-DB runner to target org vs branch
     *        schemas without moving or renumbering migration files.
     */
    public function __construct(
        private Database $db,
        array|string $paths,
        private ?array $only = null
    ) {
        $this->addPaths((array)$paths);
        $this->repo = new MigrationRepository($this->db);
        $this->lock = new MigrationLock($this->db);
        if ($this->only !== null) {
            // Hash-set for O(1) basename membership tests.
            $this->only = array_fill_keys(array_values($this->only), true);
        }
    }

    public function addPath(string $path): void
    {
        $path = rtrim($path, '/\\');
        if (is_dir($path) && !in_array($path, $this->paths, true)) {
            $this->paths[] = $path;
        }
    }

    public function addPaths(array $paths): void
    {
        foreach ($paths as $p) {
            $this->addPath($p);
        }
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

                if (!$this->db->_inTransaction()) {
                    $this->db->_beginTransaction();
                }
                try {
                    $migration->up($this->db);
                    $this->repo->markRan($name, $batch);
                    if ($this->db->_inTransaction()) {
                        $this->db->_commit();
                    }
                    $ran[] = $name;
                } catch (\Throwable $e) {
                    if ($this->db->_inTransaction()) {
                        $this->db->_rollBack();
                    }
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
                $file = $this->findMigrationFile($migrationName);
                if (!$file) {
                    throw new RuntimeException("Cannot rollback: migration file missing: {$migrationName}");
                }

                $migration = $this->loadMigration($file);

                if (!$this->db->_inTransaction()) {
                    $this->db->_beginTransaction();
                }
                try {
                    $migration->down($this->db);
                    $this->repo->deleteMark($migrationName);
                    if ($this->db->_inTransaction()) {
                        $this->db->_commit();
                    }
                    $rolled[] = $migrationName;
                } catch (\Throwable $e) {
                    if ($this->db->_inTransaction()) {
                        $this->db->_rollBack();
                    }
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
        $all = [];
        foreach ($this->paths as $path) {
            $files = glob($path . DIRECTORY_SEPARATOR . '*.php');
            if (is_array($files)) {
                $all = array_merge($all, $files);
            }
        }
        $all = array_unique($all);
        sort($all);

        if ($this->only !== null) {
            $all = array_values(array_filter(
                $all,
                fn(string $f) => isset($this->only[basename($f)])
            ));
        }

        return $all;
    }

    private function findMigrationFile(string $name): ?string
    {
        foreach ($this->paths as $path) {
            $candidate = $path . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate)) return $candidate;
        }
        return null;
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
