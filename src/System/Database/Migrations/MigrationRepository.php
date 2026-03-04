<?php
declare(strict_types=1);

namespace System\Database\Migrations;

use PDO;
use System\Database\Database;

final class MigrationRepository
{
    private string $tableName;

    public function __construct(private Database $db, ?string $tableName = null)
    {
        $table = $tableName ?? 'yt_migrations';

        // Validate table name to prevent SQL injection
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid migration table name: {$table}");
        }

        $this->tableName = $table;
    }

    public function ensureTable(): void
    {
        $table = $this->tableName;
        $driver = strtolower((string)$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME));

        if ($driver === 'sqlite') {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS {$table} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration TEXT NOT NULL UNIQUE,
                    batch INTEGER NOT NULL,
                    applied_at TEXT NOT NULL DEFAULT (datetime('now'))
                );
            ");
            return;
        }

        // mysql/mariadb
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS {$table} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }


    /** @return array<string,true> */
    public function ranMap(): array
    {
        $stmt = $this->db->execute("SELECT migration FROM {$this->tableName}");
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($rows)) $rows = [];
        return array_fill_keys($rows, true);
    }

    public function nextBatch(): int
    {
        $stmt = $this->db->execute("SELECT COALESCE(MAX(batch), 0) + 1 FROM {$this->tableName}");
        $val = $stmt->fetchColumn();
        return (int)$val;
    }

    public function lastBatch(): int
    {
        $stmt = $this->db->execute("SELECT COALESCE(MAX(batch), 0) FROM {$this->tableName}");
        $val = $stmt->fetchColumn();
        return (int)$val;
    }

    /** @return string[] */
    public function migrationsInBatch(int $batch): array
    {
        $stmt = $this->db->prepare("SELECT migration FROM {$this->tableName} WHERE batch = ? ORDER BY id DESC");
        $stmt->execute([$batch]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return is_array($rows) ? $rows : [];
    }

    public function markRan(string $migration, int $batch): void
    {
        $stmt = $this->db->prepare("INSERT INTO {$this->tableName} (migration, batch) VALUES (?, ?)");
        $stmt->execute([$migration, $batch]);
    }

    public function deleteMark(string $migration): void
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->tableName} WHERE migration = ?");
        $stmt->execute([$migration]);
    }
}
