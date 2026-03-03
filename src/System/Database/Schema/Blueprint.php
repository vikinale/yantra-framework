<?php
declare(strict_types=1);

namespace System\Database\Schema;

/**
 * Class Blueprint
 * 
 * Defines a table schema (columns, indexes, commands).
 * Supports both MySQL and SQLite DDL generation.
 */
class Blueprint
{
    private string $table;
    private array $columns = [];
    private array $commands = [];
    private bool $creating = false;

    /**
     * Database driver: 'mysql' or 'sqlite'.
     * Defaults to 'mysql'. Override via setDriver() or pass to toSql().
     */
    private string $driver = 'mysql';

    public function __construct(string $table)
    {
        // Sanitize table name: only allow alphanumeric, underscores, dots (for schema.table)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
        $this->table = $table;
    }

    /**
     * Set the database driver for DDL generation.
     * @param string $driver 'mysql' or 'sqlite'
     */
    public function setDriver(string $driver): self
    {
        $this->driver = strtolower($driver);
        return $this;
    }

    public function create(): void
    {
        $this->creating = true;
    }

    public function drop(): void
    {
        $this->commands[] = "DROP TABLE IF EXISTS `{$this->table}`";
    }

    // -- Column Types --

    public function increments(string $name): self
    {
        return $this->addColumn($name, '__AUTO_INCREMENT_PK__');
    }

    public function bigIncrements(string $name): self
    {
        return $this->addColumn($name, '__BIG_AUTO_INCREMENT_PK__');
    }

    public function integer(string $name): self
    {
        return $this->addColumn($name, 'INTEGER');
    }

    public function bigInteger(string $name): self
    {
        return $this->addColumn($name, $this->driver === 'sqlite' ? 'INTEGER' : 'BIGINT');
    }

    public function tinyInteger(string $name): self
    {
        return $this->addColumn($name, $this->driver === 'sqlite' ? 'INTEGER' : 'TINYINT');
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): self
    {
        return $this->addColumn($name, "DECIMAL($precision,$scale)");
    }

    public function float(string $name): self
    {
        return $this->addColumn($name, $this->driver === 'sqlite' ? 'REAL' : 'FLOAT');
    }

    public function double(string $name): self
    {
        return $this->addColumn($name, $this->driver === 'sqlite' ? 'REAL' : 'DOUBLE');
    }

    public function string(string $name, int $length = 255): self
    {
        return $this->addColumn($name, "VARCHAR($length)");
    }

    public function text(string $name): self
    {
        return $this->addColumn($name, 'TEXT');
    }

    public function longText(string $name): self
    {
        return $this->addColumn($name, $this->driver === 'sqlite' ? 'TEXT' : 'LONGTEXT');
    }
    
    public function boolean(string $name): self 
    {
        return $this->addColumn($name, $this->driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)');
    }

    public function json(string $name): self
    {
        return $this->addColumn($name, $this->driver === 'sqlite' ? 'TEXT' : 'JSON');
    }

    public function enum(string $name, array $values): self
    {
        if ($this->driver === 'sqlite') {
            return $this->addColumn($name, 'TEXT');
        }
        $escaped = array_map(fn($v) => "'" . str_replace("'", "''", (string)$v) . "'", $values);
        return $this->addColumn($name, "ENUM(" . implode(',', $escaped) . ")");
    }

    public function date(string $name): self
    {
        return $this->addColumn($name, 'DATE');
    }

    public function datetime(string $name): self
    {
        return $this->addColumn($name, 'DATETIME');
    }

    public function timestamp(string $name): self
    {
        return $this->addColumn($name, 'TIMESTAMP');
    }

    public function timestamps(): void
    {
        $this->datetime('created_at')->nullable();
        $this->datetime('updated_at')->nullable();
    }

    public function binary(string $name): self
    {
        return $this->addColumn($name, 'BLOB');
    }

    // -- Modifiers --

    public function nullable(): self
    {
        $last = array_key_last($this->columns);
        if ($last !== null) {
            $this->columns[$last]['nullable'] = true;
        }
        return $this;
    }

    public function default(mixed $value): self
    {
        $last = array_key_last($this->columns);
        if ($last !== null) {
            if (is_string($value)) {
                $value = "'" . str_replace("'", "''", $value) . "'";
            } elseif (is_bool($value)) {
                $value = $value ? 1 : 0;
            } elseif (is_null($value)) {
                $value = 'NULL';
            }
            $this->columns[$last]['default'] = $value;
        }
        return $this;
    }

    public function unsigned(): self
    {
        $last = array_key_last($this->columns);
        if ($last !== null) {
            $this->columns[$last]['unsigned'] = true;
        }
        return $this;
    }

    public function unique(): self
    {
        $last = array_key_last($this->columns);
        if ($last !== null) {
            $this->columns[$last]['unique'] = true;
        }
        return $this;
    }

    public function index(string ...$columns): self
    {
        $cols = implode('`, `', $columns);
        $indexName = 'idx_' . $this->table . '_' . implode('_', $columns);
        $this->commands[] = "CREATE INDEX IF NOT EXISTS `{$indexName}` ON `{$this->table}` (`{$cols}`)";
        return $this;
    }

    public function foreign(string $column, string $referencesTable, string $referencesColumn = 'id', string $onDelete = 'CASCADE', string $onUpdate = 'CASCADE'): self
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $referencesTable)) {
            throw new \InvalidArgumentException("Invalid reference table name: {$referencesTable}");
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $referencesColumn)) {
            throw new \InvalidArgumentException("Invalid reference column name: {$referencesColumn}");
        }

        $last = array_key_last($this->columns);
        if ($last !== null) {
            $this->columns[$last]['foreign'] = [
                'table' => $referencesTable,
                'column' => $referencesColumn,
                'onDelete' => strtoupper($onDelete),
                'onUpdate' => strtoupper($onUpdate),
            ];
        }
        return $this;
    }

    // -- Internals --

    private function addColumn(string $name, string $type): self
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid column name: {$name}");
        }
        $this->columns[] = [
            'name' => $name,
            'type' => $type,
            'nullable' => false,
            'default' => null,
            'unsigned' => false,
            'unique' => false,
            'foreign' => null,
        ];
        return $this;
    }

    private function resolveColumnType(string $type): string
    {
        if ($this->driver === 'sqlite') {
            return match ($type) {
                '__AUTO_INCREMENT_PK__' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                '__BIG_AUTO_INCREMENT_PK__' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                default => $type,
            };
        }

        return match ($type) {
            '__AUTO_INCREMENT_PK__' => 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            '__BIG_AUTO_INCREMENT_PK__' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            default => $type,
        };
    }

    public function toSql(?string $driver = null): array
    {
        if ($driver !== null) {
            $this->driver = strtolower($driver);
        }

        $sql = [];
        $foreignKeys = [];

        if ($this->creating) {
            $cols = [];
            foreach ($this->columns as $col) {
                $resolvedType = $this->resolveColumnType($col['type']);
                $line = "`{$col['name']}` {$resolvedType}";

                if ($col['unsigned'] && $this->driver !== 'sqlite'
                    && !str_contains($resolvedType, 'UNSIGNED')
                    && !str_contains($resolvedType, 'PRIMARY KEY')) {
                    $line .= ' UNSIGNED';
                }
                
                if (!$col['nullable']) {
                     if (!str_contains($resolvedType, 'PRIMARY KEY')) {
                         $line .= ' NOT NULL';
                     }
                }
                
                if ($col['default'] !== null) {
                    $line .= " DEFAULT {$col['default']}";
                }

                if ($col['unique']) {
                    $line .= ' UNIQUE';
                }
                
                $cols[] = $line;

                if ($col['foreign'] !== null) {
                    $fk = $col['foreign'];
                    $foreignKeys[] = "FOREIGN KEY (`{$col['name']}`) REFERENCES `{$fk['table']}` (`{$fk['column']}`) ON DELETE {$fk['onDelete']} ON UPDATE {$fk['onUpdate']}";
                }
            }

            $allDefs = array_merge($cols, $foreignKeys);
            $colsStr = implode(', ', $allDefs);

            if ($this->driver === 'mysql') {
                $sql[] = "CREATE TABLE IF NOT EXISTS `{$this->table}` ($colsStr) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            } else {
                $sql[] = "CREATE TABLE IF NOT EXISTS `{$this->table}` ($colsStr)";
            }
        }

        foreach ($this->commands as $cmd) {
            $sql[] = $cmd;
        }

        return $sql;
    }
}
