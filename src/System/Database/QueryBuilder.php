<?php
declare(strict_types=1);

namespace System\Database;

use System\Database\Exceptions\QueryException;
use System\Database\Support\Identifier;

abstract class QueryBuilder
{
    protected Database $db;

    protected string $table = '';

    /** @var array<int,string> */
    protected array $select = ['*'];

    /** @var array<int,string> */
    protected array $selectRaw = [];

    /** @var array<int,array{type:string,table:string,first:string,op:string,second:string}> */
    protected array $joins = [];

    /** @var array<int,array{boolean:string,column:string,operator:string,value:mixed}> */
    protected array $wheres = [];

    /** @var array<int,string> */
    protected array $groupBy = [];

    /** @var array<int,array{column:string,direction:string}> */
    protected array $orderBy = [];

    protected ?int $limit = null;
    protected ?int $offset = null;

    /** @var array<string,mixed> */
    protected array $bindings = [];

    /** DISTINCT for SELECT */
    protected bool $distinct = false;

    /** INSERT IGNORE (MySQL/MariaDB) */
    protected bool $ignore = false;

    /**
     * ON DUPLICATE KEY UPDATE data (MySQL/MariaDB).
     * @var array<string,mixed>
     */
    protected array $onDuplicate = [];

    /**
     * WITH (CTE) clauses. Treated as trusted raw SQL by caller.
     * @var array<string,string>
     */
    protected array $with = [];

    /**
     * Soft delete support.
     * If set, softDelete() will update this column instead of physical delete.
     */
    protected ?string $softDeleteColumn = null;
    protected mixed $softDeleteValue = 1;

    private int $pCounter = 0;

    public function reset(): void
    {
        $this->select = ['*'];
        $this->selectRaw = [];
        $this->joins = [];
        $this->wheres = [];
        $this->groupBy = [];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;
        $this->bindings = [];
        $this->distinct = false;
        $this->ignore = false;
        $this->onDuplicate = [];
        $this->with = [];
        $this->softDeleteColumn = null;
        $this->softDeleteValue = 1;
        $this->pCounter = 0;
    }

    /* ----------------- core fluent API ----------------- */

    public function from(string $table): static
    {
        $this->table = Identifier::table($table);
        return $this;
    }

    public function table(string $table): static
    {
        return $this->from($table);
    }

    public function select(string ...$columns): static
    {
        if ($columns === []) {
            $this->select = ['*'];
            return $this;
        }

        $this->select = Identifier::columnList($columns);
        return $this;
    }

    /**
     * Unsafe unless trusted input.
     */
    public function selectRaw(string $raw): static
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new QueryException('selectRaw() cannot be empty.');
        }
        $this->selectRaw[] = $raw;
        return $this;
    }

    public function distinct(bool $on = true): static
    {
        $this->distinct = $on;
        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): static
    {
        $type = strtoupper(trim($type));
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT'], true)) {
            throw new QueryException('Invalid join type.', ['type' => $type]);
        }

        $this->joins[] = [
            'type'   => $type,
            'table'  => Identifier::table($table),
            'first'  => Identifier::column($first),
            'op'     => Identifier::joinOperator($operator),
            'second' => Identifier::column($second),
        ];

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function where(string $column, string $operator, mixed $value, string $boolean = 'AND'): static
    {
        $boolean = strtoupper(trim($boolean));
        if ($boolean !== 'OR') $boolean = 'AND';

        $op = strtoupper(trim($operator));
        $allowedOps = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT', 'BETWEEN', 'NOT BETWEEN'];
        if (!in_array($op, $allowedOps, true)) {
            throw new QueryException('Invalid WHERE operator.', ['operator' => $operator]);
        }

        $this->wheres[] = [
            'boolean'  => $boolean,
            'column'   => Identifier::column($column),
            'operator' => $op,
            'value'    => $value,
        ];

        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): static
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereIn(string $column, array $values, string $boolean = 'AND'): static
    {
        return $this->where($column, 'IN', $values, $boolean);
    }

    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): static
    {
        return $this->where($column, 'NOT IN', $values, $boolean);
    }

    public function whereLike(string $column, string $value, string $boolean = 'AND'): static
    {
        return $this->where($column, 'LIKE', $value, $boolean);
    }

    public function whereNotLike(string $column, string $value, string $boolean = 'AND'): static
    {
        return $this->where($column, 'NOT LIKE', $value, $boolean);
    }

    public function whereNull(string $column, string $boolean = 'AND'): static
    {
        return $this->where($column, 'IS', null, $boolean);
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): static
    {
        return $this->where($column, 'IS NOT', null, $boolean);
    }

    /**
     * BETWEEN expects [$from, $to]
     */
    public function whereBetween(string $column, mixed $from, mixed $to, string $boolean = 'AND'): static
    {
        return $this->where($column, 'BETWEEN', [$from, $to], $boolean);
    }

    public function whereNotBetween(string $column, mixed $from, mixed $to, string $boolean = 'AND'): static
    {
        return $this->where($column, 'NOT BETWEEN', [$from, $to], $boolean);
    }

    public function groupBy(string ...$columns): static
    {
        if ($columns === []) return $this;
        $this->groupBy = array_merge($this->groupBy, Identifier::columnList($columns));
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orderBy[] = [
            'column'    => Identifier::column($column),
            'direction' => Identifier::direction($direction),
        ];
        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    public function clearOrderBy(): static
    {
        $this->orderBy = [];
        return $this;
    }

    public function clearGroupBy(): static
    {
        $this->groupBy = [];
        return $this;
    }

    public function clearLimitOffset(): static
    {
        $this->limit = null;
        $this->offset = null;
        return $this;
    }

    /**
     * WITH (CTE). Treated as trusted raw SQL by caller.
     * Example:
     *   ->with('recent', 'SELECT ...')
     */
    public function with(string $cteName, string $cteSql): static
    {
        $cteName = trim($cteName);
        $cteSql  = trim($cteSql);
        if ($cteName === '' || $cteSql === '') {
            throw new QueryException('with() requires a CTE name and SQL.');
        }
        // CTE names should be simple identifiers
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $cteName) !== 1) {
            throw new QueryException('Invalid CTE name.', ['cte' => $cteName]);
        }
        $this->with[$cteName] = $cteSql;
        return $this;
    }

    /**
     * INSERT IGNORE toggle (MySQL/MariaDB).
     */
    public function ignore(bool $on = true): static
    {
        $this->ignore = $on;
        return $this;
    }

    /**
     * ON DUPLICATE KEY UPDATE (MySQL/MariaDB).
     * Values are bound safely.
     *
     * Example:
     *   ->onDuplicateKeyUpdate(['updated_at' => date('c')])
     */
    public function onDuplicateKeyUpdate(array $data): static
    {
        if ($data === []) {
            throw new QueryException('onDuplicateKeyUpdate() cannot be empty.');
        }
        foreach ($data as $col => $_) {
            if (!is_string($col) || trim($col) === '') {
                throw new QueryException('onDuplicateKeyUpdate() columns must be strings.');
            }
            $safe = Identifier::column($col);
            if (str_contains($safe, '.')) {
                throw new QueryException('onDuplicateKeyUpdate() column cannot include table prefix.', ['column' => $safe]);
            }
        }
        $this->onDuplicate = $data;
        return $this;
    }

    /**
     * Configure soft delete behavior.
     * Example:
     *   ->softDeleteColumn('deleted', 1) OR ->softDeleteColumn('deleted_at', date('c'))
     */
    public function softDeleteColumn(string $column, mixed $value = 1): static
    {
        $column = Identifier::column($column);
        if (str_contains($column, '.')) {
            throw new QueryException('softDeleteColumn() cannot include table prefix.', ['column' => $column]);
        }
        $this->softDeleteColumn = $column;
        $this->softDeleteValue  = $value;
        return $this;
    }

    /** @return array<string,mixed> */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /* ----------------- SQL builders ----------------- */

    public function getSql(): string
    {
        if ($this->table === '') {
            throw new QueryException('No table set. Call table()/from() first.');
        }

        $sql = $this->buildWithClause();
        $sql .= 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . $this->buildSelectClause() . ' FROM ' . $this->table;

        if ($this->joins !== []) {
            $sql .= ' ' . $this->buildJoinClause();
        }

        $where = $this->buildWhereClause();
        if ($where !== '') {
            $sql .= ' ' . $where;
        }

        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if ($this->orderBy !== []) {
            $parts = [];
            foreach ($this->orderBy as $o) {
                $parts[] = $o['column'] . ' ' . $o['direction'];
            }
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . (int)$this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . (int)$this->offset;
        }

        return $sql;
    }

    /** @return array<int,array<string,mixed>> */
    public function get(): array
    {
        $sql = $this->getSql();
        return $this->db->fetchAll($sql, $this->bindings);
    }

    /** @return array<string,mixed>|null */
    public function first(): ?array
    {
        $clone = clone $this;
        $clone->limit(1);
        $sql = $clone->getSql();
        return $this->db->fetch($sql, $clone->bindings);
    }

    /**
     * Insert (safe: validates columns)
     * @param array<string,mixed> $data
     */
    public function insert(array $data): bool
    {
        if ($this->table === '') {
            throw new QueryException('No table set. Call table()/from() first.');
        }
        if ($data === []) {
            throw new QueryException('Insert data cannot be empty.');
        }

        $cols = [];
        $placeholders = [];
        $bindings = [];

        foreach ($data as $col => $val) {
            if (!is_string($col)) {
                throw new QueryException('Insert column must be string.');
            }
            $col = Identifier::column($col);
            if (str_contains($col, '.')) {
                throw new QueryException('Insert column cannot include table prefix.', ['column' => $col]);
            }

            $p = $this->nextPlaceholder();
            $cols[] = $col;
            $placeholders[] = $p;
            $bindings[substr($p, 1)] = $val;
        }

        $sql = ($this->ignore ? 'INSERT IGNORE INTO ' : 'INSERT INTO ')
            . $this->table
            . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';

        if ($this->onDuplicate !== []) {
            $pairs = [];
            foreach ($this->onDuplicate as $col => $val) {
                $safeCol = Identifier::column($col);
                if (str_contains($safeCol, '.')) {
                    throw new QueryException('onDuplicateKeyUpdate column cannot include table prefix.', ['column' => $safeCol]);
                }
                $p = $this->nextPlaceholder();
                $pairs[] = $safeCol . ' = ' . $p;
                $bindings[substr($p, 1)] = $val;
            }
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $pairs);
        }

        $this->db->execute($sql, $bindings);
        return true;
    }

    /**
     * Batch insert (safe). Supports INSERT IGNORE and ON DUPLICATE KEY UPDATE.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public function batchInsert(array $rows): bool
    {
        if ($this->table === '') {
            throw new QueryException('No table set. Call table()/from() first.');
        }
        if ($rows === []) {
            throw new QueryException('Batch insert rows cannot be empty.');
        }

        // Determine columns from first row
        $first = reset($rows);
        if (!is_array($first) || $first === []) {
            throw new QueryException('Batch insert first row invalid/empty.');
        }

        $colKeys = array_keys($first);
        foreach ($colKeys as $c) {
            if (!is_string($c) || trim($c) === '') {
                throw new QueryException('Batch insert column keys must be strings.');
            }
        }

        // Validate all rows have same keys
        foreach ($rows as $idx => $r) {
            if (!is_array($r) || array_keys($r) !== $colKeys) {
                throw new QueryException('All batch insert rows must have identical column keys.', ['row' => $idx]);
            }
        }

        $cols = [];
        foreach ($colKeys as $c) {
            $safe = Identifier::column($c);
            if (str_contains($safe, '.')) {
                throw new QueryException('Batch insert column cannot include table prefix.', ['column' => $safe]);
            }
            $cols[] = $safe;
        }

        $bindings = [];
        $valuesSql = [];

        foreach ($rows as $r) {
            $phs = [];
            foreach ($colKeys as $c) {
                $p = $this->nextPlaceholder();
                $phs[] = $p;
                $bindings[substr($p, 1)] = $r[$c];
            }
            $valuesSql[] = '(' . implode(', ', $phs) . ')';
        }

        $sql = ($this->ignore ? 'INSERT IGNORE INTO ' : 'INSERT INTO ')
            . $this->table
            . ' (' . implode(', ', $cols) . ') VALUES ' . implode(', ', $valuesSql);

        if ($this->onDuplicate !== []) {
            $pairs = [];
            foreach ($this->onDuplicate as $col => $val) {
                $safeCol = Identifier::column($col);
                if (str_contains($safeCol, '.')) {
                    throw new QueryException('onDuplicateKeyUpdate column cannot include table prefix.', ['column' => $safeCol]);
                }
                $p = $this->nextPlaceholder();
                $pairs[] = $safeCol . ' = ' . $p;
                $bindings[substr($p, 1)] = $val;
            }
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $pairs);
        }

        $this->db->execute($sql, $bindings);
        return true;
    }

    /**
     * Update (safe: validates columns)
     * @param array<string,mixed> $data
     */
    public function update(array $data): int
    {
        if ($this->table === '') {
            throw new QueryException('No table set. Call table()/from() first.');
        }
        if ($data === []) {
            throw new QueryException('Update data cannot be empty.');
        }

        $sets = [];
        $bindings = [];

        foreach ($data as $col => $val) {
            if (!is_string($col)) {
                throw new QueryException('Update column must be string.');
            }
            $col = Identifier::column($col);
            if (str_contains($col, '.')) {
                throw new QueryException('Update column cannot include table prefix.', ['column' => $col]);
            }

            $p = $this->nextPlaceholder();
            $sets[] = $col . ' = ' . $p;
            $bindings[substr($p, 1)] = $val;
        }

        $where = $this->buildWhereClause();
        if ($where === '') {
            throw new QueryException('Refusing to UPDATE without WHERE clause.');
        }

        $sql = $this->buildWithClause();
        $sql .= 'UPDATE ' . $this->table . ' SET ' . implode(', ', $sets) . ' ' . $where;

        // merge where bindings
        $bindings = $bindings + $this->bindings;

        return $this->db->execute($sql, $bindings)->rowCount();
    }

    /**
     * Physical delete (refuses without WHERE).
     * If softDeleteColumn() is configured, prefer softDelete() instead.
     */
    public function delete(): int
    {
        if ($this->table === '') {
            throw new QueryException('No table set. Call table()/from() first.');
        }

        $where = $this->buildWhereClause();
        if ($where === '') {
            throw new QueryException('Refusing to DELETE without WHERE clause.');
        }

        $sql = $this->buildWithClause();
        $sql .= 'DELETE FROM ' . $this->table . ' ' . $where;
        return $this->db->execute($sql, $this->bindings)->rowCount();
    }

    /**
     * Soft delete (UPDATE ... SET softDeleteColumn = softDeleteValue).
     * Requires softDeleteColumn() to be configured.
     */
    public function softDelete(): int
    {
        if ($this->table === '') {
            throw new QueryException('No table set. Call table()/from() first.');
        }
        if ($this->softDeleteColumn === null) {
            throw new QueryException('Soft delete not configured. Call softDeleteColumn() first.');
        }

        $where = $this->buildWhereClause();
        if ($where === '') {
            throw new QueryException('Refusing to SOFT DELETE without WHERE clause.');
        }

        $p = $this->nextPlaceholder();
        $bindings = [substr($p, 1) => $this->softDeleteValue] + $this->bindings;

        $sql = $this->buildWithClause();
        $sql .= 'UPDATE ' . $this->table . ' SET ' . $this->softDeleteColumn . ' = ' . $p . ' ' . $where;

        return $this->db->execute($sql, $bindings)->rowCount();
    }

    public function count(string $column = '*'): int
    {
        if ($this->table === '') {
            throw new QueryException('No table set. Call table()/from() first.');
        }

        $col = ($column === '*') ? '*' : Identifier::column($column);

        $sql = $this->buildWithClause();
        $sql .= 'SELECT COUNT(' . $col . ') AS c FROM ' . $this->table;

        if ($this->joins !== []) {
            $sql .= ' ' . $this->buildJoinClause();
        }

        $where = $this->buildWhereClause();
        if ($where !== '') {
            $sql .= ' ' . $where;
        }

        $row = $this->db->fetch($sql, $this->bindings);
        return (int)($row['c'] ?? 0);
    }

    /* ----------------- internal builders ----------------- */

    private function buildWithClause(): string
    {
        if ($this->with === []) return '';
        $parts = [];
        foreach ($this->with as $name => $cteSql) {
            $parts[] = $name . ' AS (' . $cteSql . ')';
        }
        return 'WITH ' . implode(', ', $parts) . ' ';
    }

    private function buildSelectClause(): string
    {
        $parts = [];

        foreach ($this->select as $c) {
            $parts[] = $c;
        }
        foreach ($this->selectRaw as $raw) {
            $parts[] = $raw; // raw is explicit & trusted by caller
        }

        return implode(', ', $parts);
    }

    private function buildJoinClause(): string
    {
        $parts = [];
        foreach ($this->joins as $j) {
            $parts[] = $j['type'] . ' JOIN ' . $j['table'] . ' ON ' . $j['first'] . ' ' . $j['op'] . ' ' . $j['second'];
        }
        return implode(' ', $parts);
    }

    private function buildWhereClause(): string
    {
        $this->bindings = []; // rebuild each time from wheres
        if ($this->wheres === []) {
            return '';
        }

        $clauses = [];

        foreach ($this->wheres as $i => $w) {
            $boolean = ($i === 0) ? '' : (' ' . $w['boolean'] . ' ');
            $col = $w['column'];
            $op  = $w['operator'];
            $val = $w['value'];

            if ($op === 'IN' || $op === 'NOT IN') {
                if (!is_array($val) || $val === []) {
                    $clauses[] = $boolean . ($op === 'IN' ? '1=0' : '1=1');
                    continue;
                }
                $phs = [];
                foreach ($val as $v) {
                    $p = $this->nextPlaceholder();
                    $phs[] = $p;
                    $this->bindings[substr($p, 1)] = $v;
                }
                $clauses[] = $boolean . $col . ' ' . $op . ' (' . implode(', ', $phs) . ')';
                continue;
            }

            if ($op === 'BETWEEN' || $op === 'NOT BETWEEN') {
                if (!is_array($val) || count($val) !== 2) {
                    throw new QueryException('BETWEEN expects [from, to].', ['value' => $val]);
                }
                $p1 = $this->nextPlaceholder();
                $p2 = $this->nextPlaceholder();
                $this->bindings[substr($p1, 1)] = $val[0];
                $this->bindings[substr($p2, 1)] = $val[1];
                $clauses[] = $boolean . $col . ' ' . $op . ' ' . $p1 . ' AND ' . $p2;
                continue;
            }

            if ($op === 'IS' || $op === 'IS NOT') {
                if ($val === null) {
                    $clauses[] = $boolean . $col . ' ' . $op . ' NULL';
                    continue;
                }
                $op = ($op === 'IS') ? '=' : '!=';
            }

            $p = $this->nextPlaceholder();
            $this->bindings[substr($p, 1)] = $val;
            $clauses[] = $boolean . $col . ' ' . $op . ' ' . $p;
        }

        return 'WHERE ' . implode('', $clauses);
    }

    private function nextPlaceholder(): string
    {
        $name = 'p' . $this->pCounter++;
        return ':' . $name;
    }
}
