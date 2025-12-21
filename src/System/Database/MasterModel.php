<?php
namespace System\Database;

use Exception;
use PDO;
use PDOStatement;

/**
 * MasterModel class extends QueryBuilder to execute database queries.
 *
 * It holds a Database instance and provides prepare/quote helpers for QueryBuilder.
 */
abstract class MasterModel extends QueryBuilder
{
    protected Database $db;

    /**
     * @param string $table
     * @param string $primaryKey
     * @throws Exception
     */
    public function __construct(string $table, string $primaryKey)
    {
        $this->table = $table;
        $this->primaryKey = $primaryKey;
        $this->reset();
        $this->connect();
    }

    /**
     * Prepare a PDO statement via the Database wrapper.
     *
     * Database::prepare() will throw an Exception on failure, so this method
     * returns a valid PDOStatement or propagates the exception.
     *
     * @param string $query
     * @param array $options
     * @return PDOStatement
     * @throws Exception
     */
    protected function prepare(string $query, array $options = []): PDOStatement
    {
        return $this->db->prepare($query, $options);
    }

    /**
     * Return last insert id (string as returned by PDO).
     *
     * @return string
     */
    public function lastInsertId(): string
    {
        return $this->db->lastInsertId();
    }

    /**
     * Connect database instance.
     *
     * @param array|null $config Optional config array; if provided a fresh Database instance will be created.
     * @return static|false
     */
    public function connect($config = null): bool|static
    {
        try {
            if (is_array($config)) {
                $this->db = new Database($config);
            } else {
                $this->db = Database::getInstance();
            }
            // ensure connection established
            $this->db->connect();
            return $this;
        } catch (Exception $e) {
            // avoid echoing in libraries; log and return false
            if (function_exists('do_action')) {
                do_action('register_log', 'Database Error', $e->getCode(), $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Quote a string via PDO.
     *
     * @param string $string
     * @param int $type
     * @return string
     */
    public function quote(string $string, int $type = PDO::PARAM_STR): string
    {
        return $this->db->quote($string, $type);
    }
}
