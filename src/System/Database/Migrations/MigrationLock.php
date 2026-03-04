<?php
declare(strict_types=1);

namespace System\Database\Migrations;

use RuntimeException;
use System\Database\Database;

final class MigrationLock
{
    public function __construct(private Database $db)
    {
    }

    public function acquire(string $name = 'yantra_migrations', int $timeoutSeconds = 10): void
    {
        try {
            $stmt = $this->db->prepare("SELECT GET_LOCK(?, ?) AS l");
            $stmt->execute([$name, $timeoutSeconds]);
            $val = $stmt->fetchColumn();

            if ((int)$val !== 1) {
                throw new RuntimeException("Could not acquire migration lock. Another process may be running migrations.");
            }
        } catch (\PDOException $e) {
            // If DB doesn't support GET_LOCK (e.g. SQLite), do not hard-fail.
            // The transaction-per-migration still protects partial state.
        }
    }

    public function release(string $name = 'yantra_migrations'): void
    {
        try {
            $stmt = $this->db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute([$name]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
