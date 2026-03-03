<?php
declare(strict_types=1);

namespace System\Contracts;

use PDO;
use PDOStatement;

/**
 * DatabaseInterface — contract for database connections.
 *
 * Abstracts the PDO wrapper so consumers can depend on an interface
 * instead of the concrete Database singleton. Enables mock databases
 * in tests and swappable connection managers.
 */
interface DatabaseInterface
{
    /**
     * Return the underlying PDO instance (connects lazily if needed).
     */
    public function getPDO(): PDO;

    /**
     * Execute SQL with bindings and return the statement.
     *
     * @param string $sql    the SQL query
     * @param array  $params positional or named bindings
     */
    public function query(string $sql, array $params = []): PDOStatement;

    /**
     * Fetch a single row as an associative array.
     *
     * @return array|null null if no row found
     */
    public function fetch(string $sql, array $params = []): ?array;

    /**
     * Fetch all rows as an array of associative arrays.
     */
    public function fetchAll(string $sql, array $params = []): array;

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): bool;

    /**
     * Commit the current transaction.
     */
    public function commit(): bool;

    /**
     * Roll back the current transaction.
     */
    public function rollBack(): bool;

    /**
     * Check whether currently inside a transaction.
     */
    public function inTransaction(): bool;

    /**
     * Return the last inserted row ID.
     */
    public function lastInsertId(): string|false;
}
