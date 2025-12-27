<?php
declare(strict_types=1);

namespace System\Database;

use Exception;
use PDO;
use PDOException;
use PDOStatement;
use System\Config;

/**
 * Database - small PDO wrapper / connection manager
 *
 * Notes:
 * - Use Database::getInstance() to obtain the shared instance (singleton).
 * - You can call Database::getInstance(false) to get a fresh instance.
 * - Include your DB config at 'App/Config/db.php' which must return an array:
 *     ['host'=>'localhost', 'port'=>3306, 'database'=>'db', 'username'=>'user', 'password'=>'pass', 'charset'=>'utf8mb4', 'options'=>[...] ]
 */
class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;
    private array $config;

    /**
     * @param array|null $config optional DB config; if null will require 'App/Config/db.php'
     * @throws Exception
     */
    public function __construct(?array $config)
    {
        $this->config = $config ?? $this->loadConfig();
        if (!$this->config || !is_array($this->config)) {
            throw new Exception('Database configuration not found or invalid.');
        }
        $this->connect();
    }

    /**
     * Begin a transaction on the singleton instance (creates instance lazily).
     * @throws Exception
     */
    public static function beginTransaction(): bool
    {
        if (self::$instance === null) {
            self::$instance = new self(null);
        }
        return self::$instance->_beginTransaction();
    }

    /**
     * Commit on the singleton instance.
     * @throws Exception
     */
    public static function commit(): bool
    {
        if (self::$instance === null) {
            throw new Exception('No active Database instance. Call Database::getInstance() or Database::beginTransaction() first.');
        }
        return self::$instance->_commit();
    }

    /**
     * Rollback on the singleton instance.
     * @throws Exception
     */
    public static function rollBack(): bool
    {
        if (self::$instance === null) {
            throw new Exception('No active Database instance. Call Database::getInstance() or Database::beginTransaction() first.');
        }
        return self::$instance->_rollBack();
    }

    /* -----------------------
     * Instance-level transaction helpers
     * ----------------------- */
    public function _beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function _commit(): bool
    {
        return $this->pdo->commit();
    }

    public function _rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * Get instance
     *
     * @param bool $singleton true => return shared instance; false => new instance
     * @return Database
     * @throws Exception
     */
    public static function getInstance(bool $singleton = true): Database
    {
        if ($singleton === false) {
            return new self(null);
        }
        if (self::$instance === null) {
            self::$instance = new self(null);
        }
        return self::$instance;
    }

    /**
     * Return raw PDO instance.
     */
    public function getPDO(): PDO
    {
        return $this->pdo;
    }

    /**
     * Prepare a statement. Throws an Exception on failure.
     *
     * @param string $query
     * @param array $options
     * @return PDOStatement
     * @throws Exception
     */
    public function prepare(string $query, array $options = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($query, $options);
        if ($stmt === false) {
            $err = $this->pdo->errorInfo();
            throw new Exception('PDO prepare failed: ' . implode(' | ', $err) . "\nSQL: " . $query);
        }
        return $stmt;
    }

    /**
     * Connect to the database (lazy).
     *
     * @throws Exception
     */
    public function connect(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? null;
        $db   = $this->config['database'] ?? '';
        $user = $this->config['username'] ?? '';
        $pass = $this->config['password'] ?? '';
        $charset = $this->config['charset'] ?? 'utf8mb4';

        // Build DSN
        $dsn = "mysql:host={$host}";
        if (!empty($port)) {
            $dsn .= ";port={$port}";
        }
        if (!empty($db)) {
            $dsn .= ";dbname={$db}";
        }
        $dsn .= ";charset={$charset}";

        // Default PDO options (can be overridden via config['options'])
        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $options = $this->config['options'] ?? [];
        $options = $options + $defaultOptions;

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Do not leak connection details in production; rethrow a generic message
            throw new Exception('Database connection error: ' . $e->getMessage());
        }
    } 
    
    /**
     * Normalize lastInsertId - PDO returns string. Return string to keep parity with PDO.
     * Callers that need integer should cast.
     */
    public function lastInsertId(): string
    {
        return (string)$this->pdo->lastInsertId();
    }

    /**
     * Quote a value using PDO
     *
     * @param string $string
     * @param int $type
     * @return string
     */
    public function quote(string $string, int $type = PDO::PARAM_STR): string
    {
        return (string)$this->pdo->quote($string, $type);
    }

    /**
     * Execute SQL with positional bindings. Returns true on success.
     *
     * @param string $query
     * @param array $params
     * @return bool
     * @throws Exception
     */
    public function execute(string $query, array $params = []): bool
    {
        $stmt = $this->prepare($query);
        $ok = $stmt->execute($params);
        if ($ok === false) {
            $err = $stmt->errorInfo();
            throw new Exception('Query execute failed: ' . implode(' | ', $err) . "\nSQL: " . $query);
        }
        return true;
    }

    /**
     * Fetch a single row (associative by default)
     *
     * @param string $query
     * @param array $params
     * @return array|null
     * @throws Exception
     */
    public function fetch(string $query, array $params = []): ?array
    {
        $stmt = $this->prepare($query);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fetch all rows (associative by default)
     *
     * @param string $query
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function fetchAll(string $query, array $params = []): array
    {
        $stmt = $this->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return $rows === false ? [] : $rows;
    }

    /* -------------------------
     * Helpers
     * ------------------------- */

    /**
     * Load config from file. This method centralizes where config is loaded
     * and makes it easier to mock in tests.
     *
     * @return array
     */
    protected function loadConfig(): array
    {        
        $cfg =  Config::get('db');
        if(is_array($cfg))
            return $cfg;

        // fallback to default env-driven config
        return [
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: 3306,
            'database' => getenv('DB_DATABASE') ?: '',
            'username' => getenv('DB_USERNAME') ?: '',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
        ];
    }
}
