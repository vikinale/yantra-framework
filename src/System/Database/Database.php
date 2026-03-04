<?php
declare(strict_types=1);

namespace System\Database;

use Exception;
use PDO;
use PDOException;
use PDOStatement;
use System\Config;
use System\Core\Application;

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
    private ?DatabaseAdapter $adapter = null;

    /**
     * @param array|null $config optional DB config; if null will require 'App/Config/db.php'
     * @throws Exception
     */
    public function __construct(?array $config)
    {
        $this->config = $config ?? $this->loadConfig();
        //error_log(json_encode($this->config));
        if (!$this->config || !is_array($this->config)) {
            throw new Exception('Database configuration not found or invalid.');
        }
        // Do NOT connect here; connect() is lazy and will be called by getInstance()/models.
    }

    public static function sql():Model
    {
        return new Model();
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
        self::$instance->connect();
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

    /**
     * Check if a transaction is currently active on the singleton instance.
     * @throws Exception
     */
    public static function inTransaction(): bool
    {
        if (self::$instance === null) {
            return false;
        }
        self::$instance->connect();
        return self::$instance->pdo->inTransaction();
    }

    /* -----------------------
     * Instance-level transaction helpers
     * ----------------------- */
    public function _beginTransaction(): bool
    {
        $this->connect();
        return $this->pdo->beginTransaction();
    }

    public function _commit(): bool
    {
        if ($this->pdo === null) {
            throw new Exception('No active PDO connection for commit.');
        }
        return $this->pdo->commit();
    }

    public function _rollBack(): bool
    {
        if ($this->pdo === null) {
            throw new Exception('No active PDO connection for rollback.');
        }
        return $this->pdo->rollBack();
    }

    /**
     * Get instance
     *
     * @param bool $singleton true => return shared instance; false => new instance
     * @return Database
     * @throws Exception
     */
    public static function getInstance(bool $singleton = true): self
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
     * Return a DatabaseAdapter that implements DatabaseInterface.
     *
     * Provides a clean interface-based API for consumers that prefer
     * constructor injection over the static singleton pattern.
     *
     * Usage:
     *   $adapter = Database::getInstance()->getAdapter();
     *   // or via DI: type-hint DatabaseInterface in your constructor
     */
    public function getAdapter(): DatabaseAdapter
    {
        if ($this->adapter === null) {
            $this->adapter = new DatabaseAdapter($this);
        }
        return $this->adapter;
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
        $this->connect();
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

        // Default PDO options (can be overridden via config['options'])
        $defaultOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $options = $this->config['options'] ?? [];
        $options = $options + $defaultOptions;

        try {
            $driver = strtolower((string)($this->config['driver'] ?? 'mysql'));

            // 1) If DSN explicitly provided, trust it (works for any driver)
            $dsnOverride = trim((string)($this->config['DSN'] ?? ''));
            if ($dsnOverride !== '') {
                $this->pdo = new PDO(
                    $dsnOverride,
                    (string)($this->config['username'] ?? ''),
                    (string)($this->config['password'] ?? ''),
                    $options
                );
                return;
            }

            // 2) SQLite support (tests + optional production usage)
            if ($driver === 'sqlite') {
                $db = (string)($this->config['database'] ?? ':memory:');

                // Accept :memory: and file paths
                $dsn = ($db === ':memory:')
                    ? 'sqlite::memory:'
                    : 'sqlite:' . $db;

                $this->pdo = new PDO($dsn, null, null, $options);
                return;
            }

            // 3) MySQL / MariaDB default
            $host    = (string)($this->config['host'] ?? '127.0.0.1');
            $port    = $this->config['port'] ?? null;
            $dbName  = (string)($this->config['database'] ?? '');
            $user    = (string)($this->config['username'] ?? '');
            $pass    = (string)($this->config['password'] ?? '');
            $charset = (string)($this->config['charset'] ?? 'utf8mb4');

            $dsn = "mysql:host={$host}";
            if (!empty($port)) {
                $dsn .= ";port={$port}";
            }
            if ($dbName !== '') {
                $dsn .= ";dbname={$dbName}";
            }
            if ($charset !== '') {
                $dsn .= ";charset={$charset}";
            }

            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Avoid leaking secrets; keep message generic but actionable
            throw new Exception('Database connection error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute SQL and return PDOStatement (static helper).
     * Supports both positional and named bindings (PDO supports both).
     */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $db = self::getInstance();
        $db->connect();
        return $db->execute($sql, $params);
    }
    
    /**
     * Normalize lastInsertId - PDO returns string. Return string to keep parity with PDO.
     * Callers that need integer should cast.
     */
    public function lastInsertId(): string|false
    {
        $this->connect();
        return $this->pdo->lastInsertId();
    }

    /**
     * Execute raw SQL without prepare (for DDL, SET commands, backup restore).
     * Returns the number of affected rows.
     */
    public function exec(string $sql): int|false
    {
        $this->connect();
        return $this->pdo->exec($sql);
    }

    /**
     * Static convenience for lastInsertId().
     */
    public static function getLastInsertId(): string|false
    {
        return self::getInstance()->lastInsertId();
    }

    /**
     * Check if a transaction is active on this instance.
     */
    public function _inTransaction(): bool
    {
        $this->connect();
        return $this->pdo->inTransaction();
    }

    /**
     * Proxy for PDO::getAttribute (driver name, server version, etc.).
     */
    public function getAttribute(int $attribute): mixed
    {
        $this->connect();
        return $this->pdo->getAttribute($attribute);
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
        $this->connect();
        return (string)$this->pdo->quote($string, $type);
    }

    /**
     * Execute SQL with positional bindings. Returns PDOStatement on success.
     *
     * @param string $query
     * @param array $params
     * @return PDOStatement
     * @throws Exception
     */
    public function execute(string $query, array $params = []): \PDOStatement
    {
        $this->connect();

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt;
        } catch (\Throwable $e) {
            // PDOException or anything else
            error_log('DB THROWABLE: ' . $e->getMessage());
            if (isset($stmt) && $stmt instanceof \PDOStatement) {
                error_log('STMT errorInfo: ' . json_encode($stmt->errorInfo()));
            }
            error_log('PDO errorInfo: ' . json_encode($this->pdo?->errorInfo()));
            throw $e;
        }
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
        try {
            $stmt = $this->prepare($query);
            $stmt->execute($params);
        } catch (\Throwable $e) {
            error_log('PDOException: ' . $e->getMessage());
            if (isset($stmt) && $stmt instanceof \PDOStatement) {
                error_log('STMT errorInfo: ' . json_encode($stmt->errorInfo()));
            }
            error_log('PDO errorInfo: ' . json_encode($this->pdo->errorInfo()));
            throw $e;
        }
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function fetchOne(string $query, array $params = []): ?array
    {
        return $this->fetch($query, $params);
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
        // 1) Config: config/db.php (via Config::get)
        try {
            $cfg = Config::get('db');
            if (is_array($cfg) && $cfg !== []) {
                return $cfg;
            }
        } catch (\Throwable $e) {
            // ignore and fallback
        }

        // 2) Config: config/database.php (alternative naming)
        try {
            $cfg = Config::get('database');
            if (is_array($cfg) && $cfg !== []) {
                return $cfg;
            }
        } catch (\Throwable $e) {
            // ignore and fallback
        }

        // 3) Legacy loader (whatever Application::dbConfig() currently does)
        try {
            $cfg = Application::dbConfig();
            if (is_array($cfg) && $cfg !== []) {
                return $cfg;
            }
        } catch (\Throwable $e) {
            // ignore and fallback
        }

        // 4) Final fallback: environment variables
        return [
            'host'     => getenv('DB_HOST') ?: '127.0.0.1',
            'port'     => (int)(getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_DATABASE') ?: 'yantra',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset'  => getenv('DB_CHARSET') ?: 'utf8mb4',
        ];
    }

    /**
     * Reset the singleton instance (useful for test isolation).
     */
    public static function resetInstance(): void
    {
        if (self::$instance !== null) {
            self::$instance->adapter = null;
            self::$instance->pdo = null;
        }
        self::$instance = null;
    }
}
