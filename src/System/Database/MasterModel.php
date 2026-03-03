<?php
declare(strict_types=1);

namespace System\Database;

use System\Database\Exceptions\DatabaseException;
use System\Database\Support\NullLogger;

/**
 * MasterModel
 *
 * Provides:
 *  - Shared Database instance (singleton)
 *  - Base QueryBuilder
 *  - Common metadata (primary key)
 */
abstract class MasterModel extends QueryBuilder
{
    protected string $primaryKey = 'id';

    public function __construct()
    {
        $logger = $logger ?? new NullLogger();

        try {
            // Use ConnectionResolver to get the active DB instance
            $this->db = ConnectionResolver::get();

            // Ensure connection is live (Database::connect is lazy-safe)
            $this->db->connect();
        } catch (\Throwable $e) {
            $logger->error($e->getMessage(),$e->getTrace());
            throw new DatabaseException('Database connection failed.', [], 0, $e);
        }

        $this->reset();
    }

    public function setPrimaryKey(string $primaryKey): void
    {
        $pk = trim($primaryKey);
        if ($pk !== '') {
            $this->primaryKey = $pk;
        }
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function lastInsertId(): string|false
    {
        return $this->db->lastInsertId();
    }
}
