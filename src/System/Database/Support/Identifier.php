<?php
declare(strict_types=1);

namespace System\Database\Support;

use System\Database\Exceptions\QueryException;

/**
 * Identifier validation for SQL builders.
 *
 * Policy:
 *  - table:   schema.table or table (letters/digits/_ only)
 *  - column:  col or table.col (letters/digits/_ only)
 *  - no quoting here; builder relies on strict validation
 */
final class Identifier
{
    public static function table(string $table): string
    {
        $t = trim($table);
        if ($t === '' || !preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $t)) {
            throw new QueryException('Invalid table identifier.', ['table' => $table]);
        }
        return $t;
    }

    public static function column(string $column): string
    {
        $c = trim($column);
        if ($c === '' || !preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $c)) {
            throw new QueryException('Invalid column identifier.', ['column' => $column]);
        }
        return $c;
    }

    public static function columnList(array $columns): array
    {
        $out = [];
        foreach ($columns as $c) {
            if (!is_string($c)) {
                throw new QueryException('Invalid column identifier (non-string).');
            }
            $out[] = self::column($c);
        }
        return $out;
    }

    public static function direction(?string $direction): string
    {
        $d = strtoupper(trim((string)$direction));
        return ($d === 'DESC') ? 'DESC' : 'ASC';
    }

    public static function joinOperator(string $op): string
    {
        $o = trim($op);
        $allowed = ['=', '!=', '<>', '<', '>', '<=', '>='];
        if (!in_array($o, $allowed, true)) {
            throw new QueryException('Invalid join operator.', ['operator' => $op]);
        }
        return $o;
    }
}
