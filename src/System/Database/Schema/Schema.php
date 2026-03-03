<?php
declare(strict_types=1);

namespace System\Database\Schema;

use System\Database\ConnectionResolver;
use Closure;

/**
 * Class Schema
 * 
 * Facade for schema operations.
 */
class Schema
{
    /**
     * Create a new table.
     */
    public static function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $blueprint->create();
        
        $callback($blueprint);
        
        self::execute($blueprint);
    }

    /**
     * Drop a table if it exists.
     */
    public static function drop(string $table): void
    {
        $blueprint = new Blueprint($table);
        $blueprint->drop();
        
        self::execute($blueprint);
    }

    /**
     * Drop if exists (alias to drop for consistency with Laravel naming).
     */
    public static function dropIfExists(string $table): void
    {
        self::drop($table);
    }

    /**
     * Execute the blueprint's SQL.
     */
    private static function execute(Blueprint $blueprint): void
    {
        $queries = $blueprint->toSql();
        $db = ConnectionResolver::get();
        
        foreach ($queries as $sql) {
            // Using query() because execute() returns statement, we just need execution.
             $db->execute($sql);
        }
    }
}
