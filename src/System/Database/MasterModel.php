<?php
declare(strict_types=1);

namespace System\Database;

use System\Database\Exceptions\DatabaseException;
use System\Database\Support\LoggerInterface;
use System\Database\Support\NullLogger;

/**
 * MasterModel
 *
 * Provides:
 *  - Database instance (fail-fast connect)
 *  - Base QueryBuilder
 *  - Common metadata (table, primary key)
 */
abstract class MasterModel extends QueryBuilder
{
    protected string $primaryKey = 'id';

    /**
     * @param array<string,mixed> $dbConfig
     */
    public function __construct(array $dbConfig, bool $isDev = false, ?LoggerInterface $logger = null)
    {
        $logger = $logger ?? new NullLogger();

        $this->db = new Database($dbConfig, $isDev, $logger);

        // Fail-fast on connect so later calls don't explode unpredictably
        try {
            $this->db->connect();
        } catch (\Throwable $e) {
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

    public function lastInsertId(): string
    {
        return $this->db->lastInsertId();
    }
}
