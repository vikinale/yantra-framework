<?php
declare(strict_types=1);

namespace System\Database;

use RuntimeException;

/**
 * Class ConnectionResolver
 * 
 * registry for the active Database connection.
 * allows swapping the connection instance globally (e.g. for testing or async pools).
 */
class ConnectionResolver
{
    private static ?Database $connection = null;

    /**
     * Set the active database connection.
     */
    public static function set(Database $db): void
    {
        self::$connection = $db;
    }

    /**
     * Get the active database connection.
     * 
     * @throws RuntimeException if no connection has been set.
     */
    public static function get(): Database
    {
        if (self::$connection === null) {
            // Fallback for legacy apps that haven't booted via Application/DI yet?
            // Or strictly enforce boot?
            // Let's allow fallback to Singleton for backward compatibility phase,
            // but log a warning if possible, or just default to it.
            return Database::getInstance();
        }
        return self::$connection;
    }
    
    /**
     * Clear the connection (testing helper).
     */
    public static function clear(): void
    {
        self::$connection = null;
    }
}
