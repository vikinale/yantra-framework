<?php
declare(strict_types=1);

namespace System\Database\Support;

use System\Database\Exceptions\QueryException;

/**
 * Identifier validation + quoting for SQL builders (MySQL/MariaDB).
 *
 * Goals:
 *  - Consistent backtick-quoting across table() and column()
 *  - Strict token validation (letters/digits/_; cannot start with digit)
 *  - Controlled support for:
 *      table:  table | schema.table | table alias | table AS alias | schema.table alias | schema.table AS alias
 *      column: col | table.col | schema.table.col | table.* | schema.table.*
 *  - Explicitly rejects: spaces, quotes, parentheses, commas, operators, etc.
 */
final class Identifier
{
    /** @var string */
    private const TOKEN_RE = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var string */
    private const UNSAFE_CHARS_RE = '/[\s`\(\),\'";]/';

    /**
     * Accepts:
     *  - table
     *  - schema.table
     *  - table alias            -> "users u"
     *  - table AS alias         -> "users AS u"
     *  - schema.table alias     -> "app.users u"
     *  - schema.table AS alias  -> "app.users AS u"
     *
     * Returns:
     *  - `table`
     *  - `schema`.`table`
     *  - `table` AS u
     *  - `schema`.`table` AS u
     */
    public static function table(string $input): string
    {
        $input = str_replace('`', '', trim($input));
        if ($input === '') {
            throw new QueryException('Table name cannot be empty.');
        }

        // Quick reject obvious injection characters.
        if (preg_match(self::UNSAFE_CHARS_RE, $input) === 1) {
            // We still allow spaces for aliasing; normalize then parse below.
            // So only reject if it contains unsafe chars other than whitespace.
            if (preg_match('/[`(),\'";]/', $input) === 1) {
                throw new QueryException('Invalid characters in table expression.', ['table' => $input]);
            }
        }

        // Normalize whitespace to simplify parsing
        $input = preg_replace('/\s+/', ' ', $input) ?? $input;
        $parts = explode(' ', $input);

        if (count($parts) === 1) {
            return self::quoteTablePath($parts[0]); // table | schema.table
        }

        if (count($parts) === 2) {
            // "users u"
            [$table, $alias] = $parts;
            return self::quoteTablePath($table) . ' AS ' . self::alias($alias);
        }

        if (count($parts) === 3) {
            // "users AS u"
            [$table, $as, $alias] = $parts;
            if (strtoupper($as) !== 'AS') {
                throw new QueryException('Invalid table expression (expected AS).', ['table' => $input]);
            }
            return self::quoteTablePath($table) . ' AS ' . self::alias($alias);
        }

        // Prevent "users u ON 1=1" etc.
        throw new QueryException('Invalid table expression.', ['table' => $input]);
    }

    /**
     * Accepts:
     *  - col
     *  - table.col
     *  - schema.table.col
     *  - table.*
     *  - schema.table.*
     *
     * Returns:
     *  - `col`
     *  - `table`.`col`
     *  - `schema`.`table`.`col`
     *  - `table`.*
     *  - `schema`.`table`.*
     */

    /**
     * Strict alias validation.
     */
    public static function alias(string $alias): string
    {
        $a = trim($alias);
        if ($a === '' || preg_match(self::TOKEN_RE, $a) !== 1) {
            throw new QueryException('Invalid alias.', ['alias' => $alias]);
        }
        return $a;
    }

    public static function column(string $column): string
    {
        $column = trim($column);
        if ($column === '') {
            throw new \InvalidArgumentException('Column cannot be empty');
        }

        // allow "*"
        if ($column === '*') {
            return '*';
        }

        // Disallow obvious raw SQL / expressions here.
        // Use selectRaw/whereRaw for those.
        if (preg_match('/[()\s]/', $column)) {
            throw new \InvalidArgumentException("Invalid $column expression. Use selectRaw()/whereRaw().");
        }

        // Support qualified names: a.b or a.b.c
        $parts = explode('.', $column);

        // allow "table.*"
        if (count($parts) > 1 && end($parts) === '*') {
            array_pop($parts);
            return implode('.', array_map([self::class, 'wrapIdent'], $parts)) . '.*';
        }

        return implode('.', array_map([self::class, 'wrapIdent'], $parts));
    }

    private static function wrapIdent(string $name): string
    {
        $name = str_replace('`', '', trim($name));
        if ($name === '' || preg_match('/[^A-Za-z0-9_]/', $name)) {
            throw new \InvalidArgumentException('Invalid identifier part: ' . $name);
        }
        return '`' . $name . '`';
    }

    /**
     * @param array<int,string> $columns
     * @return array<int,string>
     */
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

    /* ----------------- internals ----------------- */

    /**
     * table path: table | schema.table
     */
    private static function quoteTablePath(string $name): string
    {
        $n = trim($name);
        if ($n === '') {
            throw new QueryException('Table name cannot be empty.');
        }

        if (preg_match(self::UNSAFE_CHARS_RE, $n) === 1) {
            throw new QueryException('Invalid characters in table name.', ['table' => $name]);
        }

        $parts = explode('.', $n);
        if (count($parts) === 1) {
            self::assertToken($parts[0], 'table', $name);
            return self::quoteToken($parts[0]);
        }
        if (count($parts) === 2) {
            self::assertToken($parts[0], 'table', $name);
            self::assertToken($parts[1], 'table', $name);
            return self::quoteToken($parts[0]) . '.' . self::quoteToken($parts[1]);
        }

        throw new QueryException('Invalid table name (too many qualifiers).', ['table' => $name]);
    }

    private static function assertToken(string $token, string $kind, string $original): void
    {
        if ($token === '' || preg_match(self::TOKEN_RE, $token) !== 1) {
            throw new QueryException("Invalid {$kind} identifier token.", [$kind => $original]);
        }
    }

    private static function quoteToken(string $token): string
    {
        // token is already validated; backtick quoting is safe
        return '`' . $token . '`';
    }
}
