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

    /**
     * Named connection this model resolves through (multi-DB topology).
     * Defaults to 'branch'; organization-scoped models override to 'org'.
     */
    protected string $connection = 'branch';

    public function __construct()
    {
        $logger = $logger ?? new NullLogger();

        try {
            // Resolve via the name-keyed ConnectionManager when available,
            // otherwise fall back to the legacy global ConnectionResolver
            // (keeps untagged callers, CLI and tests working unchanged).
            $this->db = $this->resolveConnection();

            // Ensure connection is live (Database::connect is lazy-safe)
            $this->db->connect();
        } catch (\Throwable $e) {
            $logger->error($e->getMessage(),$e->getTrace());
            throw new DatabaseException('Database connection failed.', [], 0, $e);
        }

        $this->reset();
    }

    /**
     * Resolve the Database this model should use. Prefers the model's named
     * connection from {@see ConnectionManager}; falls back to the legacy
     * {@see ConnectionResolver} (which itself falls back to the singleton).
     */
    protected function resolveConnection(): Database
    {
        if (ConnectionManager::has($this->connection)) {
            return ConnectionManager::get($this->connection);
        }

        return ConnectionResolver::get();
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
