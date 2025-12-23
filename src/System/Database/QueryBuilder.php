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
        $this->pCounter = 0;
    }

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
        $allowedOps = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT'];
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

    /** @return array<string,mixed> */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function getSql(): string
    {
        if ($this->table === '') {
            throw new QueryException('No table set. Call table()/from() first.');
        }

        $sql = 'SELECT ' . $this->buildSelectClause() . ' FROM ' . $this->table;

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
            // Disallow dotted column names in insert/update columns for safety
            if (str_contains($col, '.')) {
                throw new QueryException('Insert column cannot include table prefix.', ['column' => $col]);
            }

            $p = $this->nextPlaceholder();
            $cols[] = $col;
            $placeholders[] = $p;
            $bindings[substr($p, 1)] = $val;
        }

        $sql = 'INSERT INTO ' . $this->table
            . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';

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

        $sql = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $sets) . ' ' . $where;

        // merge where bindings
        $bindings = $bindings + $this->bindings;

        $stmt = $this->db->execute($sql, $bindings);
        return $stmt->rowCount();
    }

    public function delete(): int
    {
        if ($this->table === '') {
            throw new QueryException('No table set. Call table()/from() first.');
        }

        $where = $this->buildWhereClause();
        if ($where === '') {
            throw new QueryException('Refusing to DELETE without WHERE clause.');
        }

        $sql = 'DELETE FROM ' . $this->table . ' ' . $where;
        $stmt = $this->db->execute($sql, $this->bindings);
        return $stmt->rowCount();
    }

    public function count(string $column = '*'): int
    {
        if ($this->table === '') {
            throw new QueryException('No table set. Call table()/from() first.');
        }

        $col = ($column === '*') ? '*' : Identifier::column($column);
        $sql = 'SELECT COUNT(' . $col . ') AS c FROM ' . $this->table;

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
                    // IN () is invalid; force false/true depending on operator
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

            if ($op === 'IS' || $op === 'IS NOT') {
                if ($val === null) {
                    $clauses[] = $boolean . $col . ' ' . $op . ' NULL';
                    continue;
                }
                // If someone tries IS 'x', treat as normal comparison to avoid odd SQL
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
