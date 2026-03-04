<?php
declare(strict_types=1);

namespace System\Database;

use PDO;
use PDOStatement;
use System\Contracts\DatabaseInterface;

/**
 * DatabaseAdapter — wraps the Database singleton to implement DatabaseInterface.
 *
 * This adapter exists because Database uses a mix of static and instance
 * methods (e.g. `Database::beginTransaction()` vs `$db->_beginTransaction()`),
 * which creates a PHP name collision if we tried to implement the interface
 * directly on Database. The adapter provides a clean interface-based API
 * that delegates to the underlying Database instance.
 *
 * Usage:
 *   $adapter = Database::getInstance()->getAdapter();
 *   // or via DI: constructor-inject DatabaseInterface
 */
final class DatabaseAdapter implements DatabaseInterface
{
    public function __construct(private Database $db)
    {
    }
 
    public function query(string $sql, array $params = []): PDOStatement
    {
        return $this->db->execute($sql, $params);
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        return $this->db->fetch($sql, $params);
    }

    
    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->db->fetch($sql, $params);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->db->fetchAll($sql, $params);
    }

    public function beginTransaction(): bool
    {
        return $this->db->_beginTransaction();
    }

    public function commit(): bool
    {
        return $this->db->_commit();
    }

    public function rollBack(): bool
    {
        return $this->db->_rollBack();
    }
 

    public function lastInsertId(): string|false
    {
        return $this->db->lastInsertId();
    }

    /**
     * Return the underlying Database instance.
     */
    public function getDatabase(): Database
    {
        return $this->db;
    }
}
