<?php
declare(strict_types=1);

namespace System\Database;

use PDO;
use PDOException;
use PDOStatement;
use System\Database\Exceptions\DatabaseException;
use System\Database\Support\LoggerInterface;
use System\Database\Support\NullLogger;

final class Database
{
    private ?PDO $pdo = null;

    /** @var array<string,mixed> */
    private array $config;

    private bool $isDev;
    private LoggerInterface $logger;

    /**
     * @param array<string,mixed> $config keys:
     *   host, dbname, username, password, charset, port, options
     */
    public function __construct(array $config, bool $isDev = false, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->isDev  = $isDev;
        $this->logger = $logger ?? new NullLogger();
    }

    public function pdo(): PDO
    {
        $this->connectIfNeeded();
        return $this->pdo;
    }

    public function connectIfNeeded(): void
    {
        if ($this->pdo instanceof PDO) {
            return;
        }

        $host    = (string)($this->config['host'] ?? 'localhost');
        $dbname  = (string)($this->config['dbname'] ?? '');
        $user    = (string)($this->config['username'] ?? '');
        $pass    = (string)($this->config['password'] ?? '');
        $charset = (string)($this->config['charset'] ?? 'utf8mb4');
        $port    = (string)($this->config['port'] ?? '');

        if ($dbname === '') {
            throw new DatabaseException('Database configuration error: missing dbname.');
        }

        $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
        if ($port !== '') {
            $dsn .= ";port={$port}";
        }

        $options = $this->config['options'] ?? [];
        if (!is_array($options)) $options = [];

        $options = $options + [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            $this->logger->error('DB connection failed', [
                'dsn' => $this->isDev ? $dsn : '[redacted]',
                'error' => $this->isDev ? $e->getMessage() : 'hidden',
            ]);

            // Do not leak sensitive details in production
            throw new DatabaseException(
                $this->isDev ? ('Database connection failed: ' . $e->getMessage()) : 'Database connection failed.',
                $this->isDev ? ['dsn' => $dsn] : [],
                0,
                $e
            );
        }
    }

    public function prepare(string $sql): PDOStatement
    {
        $this->connectIfNeeded();

        try {
            return $this->pdo->prepare($sql);
        } catch (PDOException $e) {
            throw new DatabaseException(
                $this->isDev ? ('Prepare failed: ' . $e->getMessage()) : 'Database error.',
                $this->isDev ? ['sql' => $sql] : [],
                0,
                $e
            );
        }
    }

    /**
     * @param array<string,mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): PDOStatement
    {
        $stmt = $this->prepare($sql);

        try {
            $stmt->execute($bindings);
            return $stmt;
        } catch (PDOException $e) {
            $ctx = $this->isDev ? ['sql' => $sql, 'bindings' => $bindings] : [];
            $this->logger->error('DB execute failed', $ctx);

            throw new DatabaseException(
                $this->isDev ? ('Query failed: ' . $e->getMessage()) : 'Database error.',
                $ctx,
                0,
                $e
            );
        }
    }

    /**
     * @param array<string,mixed> $bindings
     * @return array<string,mixed>|null
     */
    public function fetch(string $sql, array $bindings = []): ?array
    {
        $stmt = $this->execute($sql, $bindings);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $bindings
     * @return array<int,array<string,mixed>>
     */
    public function fetchAll(string $sql, array $bindings = []): array
    {
        $stmt = $this->execute($sql, $bindings);
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function lastInsertId(): string
    {
        $this->connectIfNeeded();
        return (string)$this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->connectIfNeeded();
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->connectIfNeeded();
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->connectIfNeeded();
        $this->pdo->rollBack();
    }
}
