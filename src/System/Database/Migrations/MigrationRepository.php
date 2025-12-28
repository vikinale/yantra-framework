<?php
declare(strict_types=1);

namespace System\Database\Migrations;

use PDO;

final class MigrationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS yt_migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL,
                ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_batch (batch),
                KEY idx_ran_at (ran_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    /** @return array<string,true> */
    public function ranMap(): array
    {
        $stmt = $this->pdo->query("SELECT migration FROM yt_migrations");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if (!is_array($rows)) $rows = [];
        return array_fill_keys($rows, true);
    }

    public function nextBatch(): int
    {
        $stmt = $this->pdo->query("SELECT COALESCE(MAX(batch), 0) + 1 FROM yt_migrations");
        $val = $stmt ? $stmt->fetchColumn() : 1;
        return (int)$val;
    }

    public function lastBatch(): int
    {
        $stmt = $this->pdo->query("SELECT COALESCE(MAX(batch), 0) FROM yt_migrations");
        $val = $stmt ? $stmt->fetchColumn() : 0;
        return (int)$val;
    }

    /** @return string[] */
    public function migrationsInBatch(int $batch): array
    {
        $stmt = $this->pdo->prepare("SELECT migration FROM yt_migrations WHERE batch = ? ORDER BY id DESC");
        $stmt->execute([$batch]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return is_array($rows) ? $rows : [];
    }

    public function markRan(string $migration, int $batch): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO yt_migrations (migration, batch) VALUES (?, ?)");
        $stmt->execute([$migration, $batch]);
    }

    public function deleteMark(string $migration): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM yt_migrations WHERE migration = ? LIMIT 1");
        $stmt->execute([$migration]);
    }
}
