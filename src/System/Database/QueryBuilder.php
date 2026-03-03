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

    /** @var array<int,array{type:string,tableExpr:string,first:string,op:string,second:string}> */
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

    /** @var array<int,string> columns to update from the incoming insert row */
    protected array $onDuplicateFromInsert = [];

    /** Alias for MySQL 8.0.20+ "AS new" syntax (optional) */
    protected ?string $insertAlias = null;

    /**
     * WITH (CTE) clauses. Treated as trusted raw SQL by caller.
     * @var array<string,string>
     */
    protected array $with = [];

    /** @var array<string,mixed> bindings coming from WITH CTEs */
    protected array $withBindings = [];

    /**
     * Soft delete support.
     * If set, softDelete() will update this column instead of physical delete.
     */
    protected ?string $softDeleteColumn = null;
    protected mixed $softDeleteValue = 1;

    private int $pCounter = 0;

    protected string $fromTable = '';       // escaped table name (or raw subquery wrapper)
    protected ?string $fromAlias = null;    // validated alias

    /** @var array<string,mixed> */
    protected array $selectBindings = [];
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
        $this->withBindings = [];
        $this->softDeleteColumn = null;
        $this->softDeleteValue = 1;
        $this->pCounter = 0;
        $this->onDuplicateFromInsert = [];
        $this->insertAlias = null;
        $this->fromTable = '';
        //$this->table = '';
        $this->fromAlias = null;
        $this->selectBindings = [];
    }

    /* ----------------- core fluent API ----------------- */
    public function table(string $table): static
    {
        $this->table = Identifier::table($table);
        return $this;
    }
    public function from(string $table): static
    {
        $this->fromTable = Identifier::table($table);
        $this->fromAlias = null;
        return $this;
    }

    public function fromAs(string $table, string $alias): static
    {
        $this->fromTable = Identifier::table($table);
        $this->fromAlias = Identifier::alias($alias);
        return $this;
    }

    private function buildFromClause(): string
    {
        if ($this->fromTable === '') {
            throw new QueryException('No table set. Call from()/fromAs() first.');
        }
        return $this->fromAlias
            ? ($this->fromTable . ' AS ' . $this->fromAlias)
            : $this->fromTable;
    }
    public function clearSelect(): static
    {
        $this->select = [];
        return $this;
    }

    public function selectExprAs(string $expr, string $alias, array $bindings = []): static
    {
        $this->clearSelect();
        $expr = trim($expr);
        if ($expr === '') throw new QueryException('selectExprAs() cannot be empty.');

        $al = Identifier::alias($alias);

        $phCount = substr_count($expr, '?');
        if ($bindings !== []) {
            if ($phCount !== count($bindings)) {
                throw new QueryException('selectExprAs() bindings count does not match placeholders.', [
                    'placeholders' => $phCount,
                    'bindings'     => count($bindings),
                ]);
            }

            foreach (array_values($bindings) as $v) {
                $p = $this->nextPlaceholder();                // :pN
                $expr = preg_replace('/\?/', $p, $expr, 1) ?? $expr;
                $this->selectBindings[substr($p, 1)] = $v;    // pN => value
            }

            if (strpos($expr, '?') !== false) {
                throw new QueryException('selectExprAs() bindings count does not match placeholders.');
            }
        } else {
            if ($phCount > 0) {
                throw new QueryException('selectExprAs() contains placeholders but no bindings were provided.', [
                    'placeholders' => $phCount,
                ]);
            }
        }

        $this->selectRaw[] = $expr . ' AS ' . $al;
        return $this;
    }

    public function select(array|string ...$columns): static
    {
        if ($columns === []) {
            $this->select = ['*'];
            return $this;
        }

        // allow ->select(['id','name']) OR ->select('id','name')
        if (count($columns) === 1 && is_array($columns[0])) {
            $columns = $columns[0];
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
            'type'      => $type,
            'tableExpr' => Identifier::table($table),
            'first'     => Identifier::column($first),
            'op'        => Identifier::joinOperator($operator),
            'second'    => Identifier::column($second),
        ];

        return $this;
    }


    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function leftJoinAs(string $table, string $alias, string $first, string $operator, string $second): static
    {
        return $this->joinAs($table, $alias, $first, $operator, $second, 'LEFT');
    }

    public function joinAs(
        string $table,
        string $alias,
        string $first,
        string $operator,
        string $second,
        string $type = 'INNER'
    ): static {

        if (preg_match('/\s/', trim($table))) {
            throw new QueryException('joinAs(): $table must not include an alias; pass plain table name.');
        }

        $type = strtoupper(trim($type));
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT'], true)) {
            throw new QueryException('Invalid join type.', ['type' => $type]);
        }

        $tableExpr = Identifier::table($table) . ' AS ' . Identifier::alias($alias);

        $this->joins[] = [
            'type'      => $type,
            'tableExpr' => $tableExpr,
            'first'     => Identifier::column($first),
            'op'        => Identifier::joinOperator($operator),
            'second'    => Identifier::column($second),
        ];

        return $this;
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

    public function whereEqual(string $column, mixed $value, string $boolean = 'AND'): static
    {
        return $this->where($column, '=', $value, $boolean);
    }

    public function orWhereEqual(string $column, mixed $value): static
    {
        return $this->orWhere($column, '=', $value);
    }

    public function whereNotEqual(string $column, mixed $value, string $boolean = 'AND'): static
    {
        return $this->where($column, '!=', $value, $boolean);
    }

    public function whereLessThan(string $column, mixed $value, string $boolean = 'AND'): static
    {
        return $this->where($column, '<', $value, $boolean);
    }

    public function whereLessThanOrEqual(string $column, mixed $value, string $boolean = 'AND'): static
    {
        return $this->where($column, '<=', $value, $boolean);
    }

    public function whereGreaterThan(string $column, mixed $value, string $boolean = 'AND'): static
    {
        return $this->where($column, '>', $value, $boolean);
    }

    public function whereGreaterThanOrEqual(string $column, mixed $value, string $boolean = 'AND'): static
    {
        return $this->where($column, '>=', $value, $boolean);
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
     * Add a raw WHERE clause.
     *
     * Security: raw SQL is trusted by caller. Bindings must be provided separately.
     *
     * Placeholder rules:
     *  - Prefer '?' placeholders. Each '?' will be replaced with an internal named placeholder (:pN).
     *  - If you pass bindings, the number of '?' in $raw MUST match the number of bindings.
     *  - If you pass no bindings, $raw must NOT contain '?'.
     */
    public function whereRaw(string $raw, array $bindings = [], string $boolean = 'AND'): static
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new QueryException('whereRaw() cannot be empty.');
        }

        $boolean = strtoupper(trim($boolean));
        if ($boolean !== 'OR') $boolean = 'AND';

        // Validate placeholder count
        $phCount = substr_count($raw, '?');
        if ($bindings !== []) {
            if ($phCount !== count($bindings)) {
                throw new QueryException('whereRaw() bindings count does not match placeholders.', [
                    'placeholders' => $phCount,
                    'bindings'     => count($bindings),
                ]);
            }
        } else {
            // This builder uses named placeholders; raw '?' without bindings would break execution.
            if ($phCount > 0) {
                throw new QueryException('whereRaw() contains placeholders but no bindings were provided.', [
                    'placeholders' => $phCount,
                ]);
            }
        }

        $this->wheres[] = [
            'boolean'  => $boolean,
            'raw'      => $raw,
            'bindings' => array_values($bindings),
        ];

        return $this;
    }

    public function orWhereRaw(string $raw, array $bindings = []): static
    {
        return $this->whereRaw($raw, $bindings, 'OR');
    }

    /**
     * Grouped WHERE conditions: ( ... )
     *
     * Example:
     *   $qb->whereGroup(function($w){
     *     $w->where('u.company_id','=',1)
     *       ->orWhereNull('u.company_id');
     *   });
     *
     * Notes:
     * - Supports qualified columns like "u.company_id" (alias/table) via Identifier::column().
     * - Produces a single whereRaw('( ... )', bindings) so it works with current placeholder system.
     */
    public function whereGroup(callable $cb, string $boolean = 'AND'): static
    {
        $boolean = strtoupper(trim($boolean));
        if ($boolean !== 'OR') $boolean = 'AND';

        $group = $this->newWhereGroupProxy();
        $cb($group);

        $sql = $group->toSql();
        if ($sql === '') {
            return $this; // no conditions added
        }

        return $this->whereRaw('(' . $sql . ')', $group->getBindings(), $boolean);
    }

    public function orWhereGroup(callable $cb): static
    {
        return $this->whereGroup($cb, 'OR');
    }

    /**
     * Internal group proxy that collects conditions and compiles them to SQL with "?" placeholders.
     * Bindings returned in same order as placeholders.
     */
    private function newWhereGroupProxy(): object
    {
        return new class {
            /** @var array<int,array{boolean:string,sql:string,bindings:array<int,mixed>}> */
            private array $parts = [];

            /** @var array<int,mixed> */
            private array $bindings = [];

            private function add(string $boolean, string $sql, array $bindings = []): self
            {
                $boolean = strtoupper(trim($boolean));
                if ($boolean !== 'OR') $boolean = 'AND';

                $sql = trim($sql);
                if ($sql === '') return $this;

                $this->parts[] = [
                    'boolean'  => $boolean,
                    'sql'      => $sql,
                    'bindings' => array_values($bindings),
                ];

                foreach (array_values($bindings) as $v) {
                    $this->bindings[] = $v;
                }

                return $this;
            }

            public function where(string $column, string $operator, mixed $value, string $boolean = 'AND'): self
            {
                $col = \System\Database\Support\Identifier::column($column);

                $op = strtoupper(trim($operator));
                $allowedOps = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT', 'BETWEEN', 'NOT BETWEEN'];
                if (!in_array($op, $allowedOps, true)) {
                    throw new \System\Database\Exceptions\QueryException('Invalid WHERE operator.', ['operator' => $operator]);
                }

                // IN / NOT IN
                if ($op === 'IN' || $op === 'NOT IN') {
                    if (!is_array($value) || $value === []) {
                        return $this->add($boolean, $op === 'IN' ? '1=0' : '1=1');
                    }
                    $ph = implode(', ', array_fill(0, count($value), '?'));
                    return $this->add($boolean, "{$col} {$op} ({$ph})", array_values($value));
                }

                // BETWEEN / NOT BETWEEN
                if ($op === 'BETWEEN' || $op === 'NOT BETWEEN') {
                    if (!is_array($value) || count($value) !== 2) {
                        throw new \System\Database\Exceptions\QueryException('BETWEEN expects [from, to].', ['value' => $value]);
                    }
                    return $this->add($boolean, "{$col} {$op} ? AND ?", [$value[0], $value[1]]);
                }

                // IS / IS NOT NULL
                if ($op === 'IS' || $op === 'IS NOT') {
                    if ($value === null) {
                        return $this->add($boolean, "{$col} {$op} NULL");
                    }
                    // fallback to = / != if not null (same as main builder)
                    $op = ($op === 'IS') ? '=' : '!=';
                }

                return $this->add($boolean, "{$col} {$op} ?", [$value]);
            }

            public function orWhere(string $column, string $operator, mixed $value): self
            {
                return $this->where($column, $operator, $value, 'OR');
            }

            public function whereNull(string $column, string $boolean = 'AND'): self
            {
                $col = \System\Database\Support\Identifier::column($column);
                return $this->add($boolean, "{$col} IS NULL");
            }

            public function orWhereNull(string $column): self
            {
                return $this->whereNull($column, 'OR');
            }

            public function whereNotNull(string $column, string $boolean = 'AND'): self
            {
                $col = \System\Database\Support\Identifier::column($column);
                return $this->add($boolean, "{$col} IS NOT NULL");
            }

            public function orWhereNotNull(string $column): self
            {
                return $this->whereNotNull($column, 'OR');
            }

            public function whereRaw(string $raw, array $bindings = [], string $boolean = 'AND'): self
            {
                $raw = trim($raw);
                if ($raw === '') {
                    throw new \System\Database\Exceptions\QueryException('whereRaw() cannot be empty.');
                }

                $phCount = substr_count($raw, '?');
                if ($bindings !== []) {
                    if ($phCount !== count($bindings)) {
                        throw new \System\Database\Exceptions\QueryException('whereRaw() bindings count does not match placeholders.', [
                            'placeholders' => $phCount,
                            'bindings'     => count($bindings),
                        ]);
                    }
                } else {
                    if ($phCount > 0) {
                        throw new \System\Database\Exceptions\QueryException('whereRaw() contains placeholders but no bindings were provided.', [
                            'placeholders' => $phCount,
                        ]);
                    }
                }

                return $this->add($boolean, $raw, array_values($bindings));
            }

            public function orWhereRaw(string $raw, array $bindings = []): self
            {
                return $this->whereRaw($raw, $bindings, 'OR');
            }

            public function whereGroup(callable $cb, string $boolean = 'AND'): self
            {
                $nested = new self();
                $cb($nested);

                $sql = $nested->toSql();
                if ($sql === '') return $this;

                return $this->add($boolean, '(' . $sql . ')', $nested->getBindings());
            }

            public function orWhereGroup(callable $cb): self
            {
                return $this->whereGroup($cb, 'OR');
            }

            public function toSql(): string
            {
                if ($this->parts === []) return '';

                $out = '';
                foreach ($this->parts as $i => $p) {
                    $out .= ($i === 0 ? '' : ' ' . $p['boolean'] . ' ') . $p['sql'];
                }
                return $out;
            }

            /** @return array<int,mixed> */
            public function getBindings(): array
            {
                return $this->bindings;
            }
        };
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
     * WITH (CTE) from a QueryBuilder, including its bindings.
     * Rewrites CTE placeholders to avoid collisions (e.g. :p0 -> :w_sel_p0).
     */
    public function withQuery(string $cteName, QueryBuilder $cteQuery): static
    {
        $cteName = trim($cteName);
        if ($cteName === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $cteName) !== 1) {
            throw new QueryException('Invalid CTE name.', ['cte' => $cteName]);
        }

        $cteSql   = $cteQuery->getSql();
        $cteBinds = $cteQuery->getBindings();

        if ($cteBinds !== []) {
            $rebased = [];
            foreach ($cteBinds as $k => $v) {
                // cte binding keys are like "p0", "p1" (without ':')
                $nk = 'w_' . $cteName . '_' . $k; // e.g. w_sel_p0
                $cteSql = str_replace(':' . $k, ':' . $nk, $cteSql);
                $rebased[$nk] = $v;
            }
            $cteBinds = $rebased;
        }

        $this->with[$cteName] = $cteSql;
        $this->withBindings   = $this->withBindings + $cteBinds;

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
     * ON DUPLICATE KEY UPDATE using incoming insert values for the given columns.
     *
     * - If $alias is provided, will generate: col = alias.col (MySQL 8.0.20+ recommended)
     * - If $alias is null, will generate:    col = VALUES(col) (works in MySQL/MariaDB; deprecated in MySQL 8.0.20+)
     *
     * @param array<int,string> $columns
     */
    public function onDuplicateKeyUpdateFromInsert(array $columns, ?string $alias = null): static
    {
        if ($columns === []) {
            throw new QueryException('onDuplicateKeyUpdateFromInsert() cannot be empty.');
        }

        $safeCols = [];
        foreach ($columns as $c) {
            if (!is_string($c) || trim($c) === '') {
                throw new QueryException('onDuplicateKeyUpdateFromInsert() columns must be strings.');
            }
            $sc = Identifier::column($c);
            if (str_contains($sc, '.')) {
                throw new QueryException('onDuplicateKeyUpdateFromInsert() column cannot include table prefix.', ['column' => $sc]);
            }
            $safeCols[] = $sc;
        }

        if ($alias !== null) {
            $alias = trim($alias);
            if ($alias === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
                throw new QueryException('Invalid insert alias.', ['alias' => $alias]);
            }
            $this->insertAlias = $alias;
        } else {
            $this->insertAlias = null;
        }

        $this->onDuplicateFromInsert = $safeCols;
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
        // Reset placeholder counter and bindings so getSql() is idempotent.
        // buildWhereClause() will rebuild all bindings from scratch.
        $this->pCounter = 0;

        $sql = $this->buildWithClause();
        $sql .= 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '')
            . $this->buildSelectClause()
            . ' FROM ' . $this->resolveFromClause();

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
    public function first()
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

        if ($this->insertAlias !== null) {
            $sql .= ' AS ' . $this->insertAlias;
        }

        if ($this->onDuplicate !== [] || $this->onDuplicateFromInsert !== []) {
            $pairs = [];

            // 1) Bound literal updates (your existing behavior)
            foreach ($this->onDuplicate as $col => $val) {
                $safeCol = Identifier::column($col);
                if (str_contains($safeCol, '.')) {
                    throw new QueryException('onDuplicateKeyUpdate column cannot include table prefix.', ['column' => $safeCol]);
                }
                $p = $this->nextPlaceholder();
                $pairs[] = $safeCol . ' = ' . $p;
                $bindings[substr($p, 1)] = $val;
            }

            // 2) Update from incoming insert values (per-row)
            foreach ($this->onDuplicateFromInsert as $safeCol) {
                // if user also specified a literal for same column, let literal win
                $rawKey = trim($safeCol, '`'); // quick check; not perfect, but avoids duplicates in common cases
                if (array_key_exists($rawKey, $this->onDuplicate)) {
                    continue;
                }

                if ($this->insertAlias !== null) {
                    $pairs[] = $safeCol . ' = ' . $this->insertAlias . '.' . $safeCol;
                } else {
                    $pairs[] = $safeCol . ' = VALUES(' . $safeCol . ')';
                }
            }

            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $pairs);
        }
        $this->db->execute($sql, $bindings);
        return true;
    }
    public function lastInsertId(): string|false{
        return $this->db->lastInsertId();
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

        if ($this->insertAlias !== null) {
            $sql .= ' AS ' . $this->insertAlias;
        }

        if ($this->onDuplicate !== [] || $this->onDuplicateFromInsert !== []) {
            $pairs = [];

            // 1) Bound literal updates (your existing behavior)
            foreach ($this->onDuplicate as $col => $val) {
                $safeCol = Identifier::column($col);
                if (str_contains($safeCol, '.')) {
                    throw new QueryException('onDuplicateKeyUpdate column cannot include table prefix.', ['column' => $safeCol]);
                }
                $p = $this->nextPlaceholder();
                $pairs[] = $safeCol . ' = ' . $p;
                $bindings[substr($p, 1)] = $val;
            }

            // 2) Update from incoming insert values (per-row)
            foreach ($this->onDuplicateFromInsert as $safeCol) {
                // if user also specified a literal for same column, let literal win
                $rawKey = trim($safeCol, '`'); // quick check; not perfect, but avoids duplicates in common cases
                if (array_key_exists($rawKey, $this->onDuplicate)) {
                    continue;
                }

                if ($this->insertAlias !== null) {
                    $pairs[] = $safeCol . ' = ' . $this->insertAlias . '.' . $safeCol;
                } else {
                    $pairs[] = $safeCol . ' = VALUES(' . $safeCol . ')';
                }
            }

            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $pairs);
        }

        $this->db->execute($sql, $bindings);
        return true;
    }

    /**
     * Update (safe: validates columns)
     * @param array<string,mixed> $data
     * @throws \Exception
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
        $sql .= 'UPDATE ' . $this->table;
        if ($this->fromAlias !== null) {
            $sql .= ' AS ' . $this->fromAlias;
        }
        $sql .= ' SET ' . implode(', ', $sets) . ' ' . $where;

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
        $sql .= 'DELETE FROM ' . $this->table;
        if ($this->fromAlias !== null) {
            $sql .= ' AS ' . $this->fromAlias;
        }
        $sql .= ' ' . $where;
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
        $sql .= 'UPDATE ' . $this->table;
        if ($this->fromAlias !== null) {
            $sql .= ' AS ' . $this->fromAlias;
        }
        $sql .= ' SET ' . $this->softDeleteColumn . ' = ' . $p . ' ' . $where;

        return $this->db->execute($sql, $bindings)->rowCount();
    }

    public function count(string $column = '*'): int
    {
        $col = ($column === '*') ? '*' : Identifier::column($column);

        $sql = $this->buildWithClause();
        $sql .= 'SELECT COUNT(' . $col . ') AS c FROM ' . $this->resolveFromClause();

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

    /**
     * Efficient existence check: SELECT 1 ... LIMIT 1
     */
    public function exists(): bool
    {
        $sql = $this->buildWithClause();
        $sql .= 'SELECT 1 AS _x FROM ' . $this->resolveFromClause();

        if ($this->joins !== []) {
            $sql .= ' ' . $this->buildJoinClause();
        }

        $where = $this->buildWhereClause();
        if ($where !== '') {
            $sql .= ' ' . $where;
        }

        $sql .= ' LIMIT 1';

        $row = $this->db->fetch($sql, $this->bindings);
        return $row !== null;
    }

    /* =====================================================
     | Pagination
     | ===================================================== */

    /**
     * Paginate query results with total count (LengthAwarePaginator).
     *
     * Runs a COUNT(*) query to determine total, then fetches the page slice.
     *
     * @param int    $perPage  Items per page (default: 15).
     * @param string $pageName Query parameter name for the page.
     * @param int|null $page   Current page (auto-detected from $_GET if null).
     * @return \System\Database\Pagination\LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): \System\Database\Pagination\LengthAwarePaginator
    {
        $perPage = max(1, $perPage);

        // Get total count using a clone (preserves current where/join state)
        $total = (clone $this)->count();

        // Resolve current page
        $currentPage = $page ?? (int) ($_GET[$pageName] ?? 1);
        $currentPage = max(1, $currentPage);

        // Calculate offset
        $offset = ($currentPage - 1) * $perPage;

        // Fetch the page slice
        $clone = clone $this;
        $clone->limit($perPage);
        $clone->offset($offset);
        $items = $clone->get();

        return new \System\Database\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            $pageName
        );
    }

    /**
     * Simple paginate without total count (Paginator).
     *
     * Fetches perPage+1 items to determine if there are more pages.
     * Faster than paginate() for large datasets (no COUNT query).
     *
     * @param int    $perPage  Items per page (default: 15).
     * @param string $pageName Query parameter name for the page.
     * @param int|null $page   Current page (auto-detected from $_GET if null).
     * @return \System\Database\Pagination\Paginator
     */
    public function simplePaginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): \System\Database\Pagination\Paginator
    {
        $perPage = max(1, $perPage);

        // Resolve current page
        $currentPage = $page ?? (int) ($_GET[$pageName] ?? 1);
        $currentPage = max(1, $currentPage);

        // Calculate offset
        $offset = ($currentPage - 1) * $perPage;

        // Fetch perPage+1 to detect if more pages exist
        $clone = clone $this;
        $clone->limit($perPage + 1);
        $clone->offset($offset);
        $items = $clone->get();

        return new \System\Database\Pagination\Paginator(
            $items,
            $perPage,
            $currentPage,
            $pageName
        );
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
            $parts[] = $j['type'] . ' JOIN ' . $j['tableExpr']
                . ' ON ' . $j['first'] . ' ' . $j['op'] . ' ' . $j['second'];
        }
        return implode(' ', $parts);
    }

    public function selectAs(string $column, string $alias): static
    {
        $col = Identifier::column($column);     // supports a.id
        $al  = Identifier::alias($alias);
        $this->select[] = $col . ' AS ' . $al;
        return $this;
    }


    private function buildWhereClause(): string
    {
        $this->bindings = $this->withBindings + $this->selectBindings;
        if ($this->wheres === []) {
            return '';
        }

        $clauses = [];

        foreach ($this->wheres as $i => $w) {
            $boolean = ($i === 0) ? '' : (' ' . $w['boolean'] . ' ');
            
            // Raw WHERE clause
            if (array_key_exists('raw', $w)) {
                $raw = trim((string)($w['raw'] ?? ''));
                if ($raw === '') {
                    throw new QueryException('whereRaw() cannot be empty.');
                }

                $vals = $w['bindings'] ?? [];
                if (!is_array($vals)) $vals = [];

                $compiled = $raw;

                // Replace '?' with internal named placeholders and bind
                if ($vals !== []) {
                    foreach (array_values($vals) as $v) {
                        $p = $this->nextPlaceholder();
                        $compiled = preg_replace('/\?/', $p, $compiled, 1) ?? $compiled;
                        $this->bindings[substr($p, 1)] = $v;
                    }

                    // If any '?' remain, mismatch
                    if (strpos($compiled, '?') !== false) {
                        throw new QueryException('whereRaw() bindings count does not match placeholders.');
                    }
                }

                $clauses[] = $boolean . $compiled;
                continue;
            }

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

     /**
     * Large-table safe copy/upsert:
     * Runs repeated INSERT ... SELECT ... WHERE (sourceWhere) AND (cursor > last) ORDER BY cursor LIMIT chunk
     * with optional ON DUPLICATE KEY UPDATE.
     *
     * Key points:
     * - Uses a cursor column for deterministic paging (must be indexed).
     * - Preserves existing WHERE/JOIN/GROUP/ORDER on the source query, but overrides ORDER/LIMIT/OFFSET for paging.
     * - Avoids placeholder collisions by rebasing placeholders in the cloned source query.
     *
     * @param QueryBuilder $source     SELECT query to copy from (must not be SELECT *).
     * @param array<int,string>|null $insertColumns Destination insert columns. If null, inferred from $source->select.
     * @param string $cursorColumn     e.g. 'id' (must exist in source SELECT table and be indexed)
     * @param int $chunk              rows per batch
     * @param int|null $maxBatches    optional safety cap
     * @param bool $requireWhereOnSource If true, refuses when source has no WHERE.
     * @return int total rows processed (sum of chunk sizes). Note: affected rows from MySQL rowCount() is not reliable for upserts.
     */
    public function copyUpsertChunked(
        QueryBuilder $source,
        ?array $insertColumns = null,
        string $cursorColumn = 'id',
        int $chunk = 5000,
        ?int $maxBatches = null,
        bool $requireWhereOnSource = false
    ): int {
        if ($this->table === '') {
            throw new QueryException('No destination table set. Call table() first.');
        }
        if ($source->fromTable === '' && $source->table === '') {
            throw new QueryException('Source query has no table set. Call from()/fromAs() or table() on the source query.');
        }
        if ($chunk < 1) {
            throw new QueryException('chunk must be >= 1', ['chunk' => $chunk]);
        }
        if ($maxBatches !== null && $maxBatches < 1) {
            throw new QueryException('maxBatches must be >= 1', ['maxBatches' => $maxBatches]);
        }
        if ($requireWhereOnSource && $source->wheres === []) {
            throw new QueryException('Refusing to copyUpsertChunked() from a source SELECT without WHERE clause.');
        }

        // Disallow selectRaw unless insertColumns explicitly provided (mapping ambiguity)
        if ($source->selectRaw !== [] && $insertColumns === null) {
            throw new QueryException(
                'copyUpsertChunked(): source selectRaw() requires explicit $insertColumns for destination mapping.'
            );
        }

        // Resolve destination columns list
        $destCols = $this->resolveCopyInsertColumns($source, $insertColumns);

        // Cursor column safety
        $cursorColSafe = Identifier::column($cursorColumn);
        if (str_contains($cursorColSafe, '.')) {
            throw new QueryException('cursorColumn cannot be qualified (no table prefix).', ['cursor' => $cursorColSafe]);
        }

        // Enforce non-star select (required)
        if ($source->select === ['*']) {
            throw new QueryException(
                "copyUpsertChunked(): source SELECT cannot be '*'. Select explicit columns."
            );
        }

        $total = 0;
        $lastCursor = null; // mixed
        $batches = 0;

        while (true) {
            if ($maxBatches !== null && $batches >= $maxBatches) {
                break;
            }
            $batches++;

            // Clone the source and apply paging without mutating original query
            $q = clone $source;

            // Ensure deterministic paging and ignore caller's ORDER/LIMIT/OFFSET (we must control them)
            $q->clearOrderBy()->clearLimitOffset();

            // Apply cursor predicate. If lastCursor is null, we do no extra predicate.
            if ($lastCursor !== null) {
                $q->where($cursorColumn, '>', $lastCursor);
            }

            $q->orderBy($cursorColumn, 'ASC')->limit($chunk);

            // IMPORTANT:
            // buildWhereClause() rebuilds $q->bindings starting from pCounter=0.
            // We must avoid collisions with destination placeholders used for onDuplicate literals.
            // We do this by rebasing source placeholders after building SQL.
            $selectSql = $q->getSql();
            $selectBindings = $q->getBindings();

            // Fetch the last cursor for the current chunk.
            // We do a separate lightweight query to get max cursor in this chunk, avoiding pulling all rows.
            // This keeps memory constant and is safe for very large tables.
            $maxCursor = $this->maxCursorForChunk($q, $cursorColumn);
            if ($maxCursor === null) {
                break; // no rows in this chunk
            }

            // Rebase source placeholders to avoid collisions with destination placeholders
            // We'll shift :p0..:pN to :s0..:sN (and update bindings accordingly)
            [$selectSql, $selectBindings] = $this->rebasePlaceholders($selectSql, $selectBindings, 'p', 's');

            // Build INSERT ... SELECT ...
            $sql = ($this->ignore ? 'INSERT IGNORE INTO ' : 'INSERT INTO ')
                . $this->table
                . ' (' . implode(', ', $destCols) . ') '
                . $selectSql;

            if ($this->insertAlias !== null) {
                $sql .= ' AS ' . $this->insertAlias;
            }

            $bindings = $selectBindings;

            // Append ON DUPLICATE KEY UPDATE
            if ($this->onDuplicate !== [] || $this->onDuplicateFromInsert !== []) {
                $pairs = [];

                foreach ($this->onDuplicate as $col => $val) {
                    $safeCol = Identifier::column((string)$col);
                    if (str_contains($safeCol, '.')) {
                        throw new QueryException(
                            'onDuplicateKeyUpdate column cannot include table prefix.',
                            ['column' => $safeCol]
                        );
                    }
                    $p = $this->nextPlaceholder(); // destination placeholders p0,p1,... are independent
                    $pairs[] = $safeCol . ' = ' . $p;
                    $bindings[substr($p, 1)] = $val;
                }

                $literalCols = [];
                foreach ($this->onDuplicate as $col => $_) {
                    $literalCols[] = Identifier::column((string)$col);
                }

                foreach ($this->onDuplicateFromInsert as $safeCol) {
                    if (in_array($safeCol, $literalCols, true)) {
                        continue;
                    }
                    if ($this->insertAlias !== null) {
                        $pairs[] = $safeCol . ' = ' . $this->insertAlias . '.' . $safeCol;
                    } else {
                        $pairs[] = $safeCol . ' = VALUES(' . $safeCol . ')';
                    }
                }

                if ($pairs !== []) {
                    $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $pairs);
                }
            }

            $this->db->execute($sql, $bindings);

            // Update cursor and totals
            $lastCursor = $maxCursor;
            $total += $chunk; // informational; see note below

            // If fewer than chunk rows were returned, we are done.
            // We can detect that by comparing lastCursor stability:
            // If next maxCursor equals current lastCursor with no progress, stop.
            // (maxCursorForChunk will return null if empty)
            // However, we do not have exact "rows in chunk" without an extra COUNT query.
            // For large tables, it's better to avoid COUNT and rely on cursor exhaustion.
        }

        return $total;
    }

    /**
     * Get max cursor value for the current chunk query without loading all rows.
     * We do:
     *   SELECT MAX(cursor) FROM ( <chunked select> ) t
     *
     * This avoids fetching the entire chunk into PHP memory.
     *
     * @return mixed|null
     */
    private function maxCursorForChunk(QueryBuilder $chunkQuery, string $cursorColumn): mixed
    {
        // Clone and convert to a MAX(cursor) query over a subquery
        $sub = $chunkQuery->getSql();
        $bind = $chunkQuery->getBindings();

        // Rebase placeholders to keep them consistent inside this helper too
        [$sub, $bind] = $this->rebasePlaceholders($sub, $bind, 'p', 'm');

        $cur = Identifier::column($cursorColumn);
        $sql = 'SELECT MAX(' . $cur . ') AS max_cursor FROM (' . $sub . ') AS _t';

        $row = $this->db->fetch($sql, $bind);
        $v = $row['max_cursor'] ?? null;

        return $v === null ? null : $v;
    }

    /**
     * Rebase placeholders in SQL/bindings from a prefix (e.g. p0,p1) to another (e.g. s0,s1).
     *
     * @param string $sql
     * @param array<string,mixed> $bindings
     * @param string $fromPrefix default 'p'
     * @param string $toPrefix   e.g. 's'
     * @return array{0:string,1:array<string,mixed>}
     */
    private function rebasePlaceholders(string $sql, array $bindings, string $fromPrefix, string $toPrefix): array
    {
        if ($bindings === []) {
            return [$sql, $bindings];
        }

        $newBindings = [];
        foreach ($bindings as $k => $v) {
            // Keys look like "p0", "p1" ...
            if (str_starts_with($k, $fromPrefix)) {
                $nk = $toPrefix . substr($k, strlen($fromPrefix));
                $newBindings[$nk] = $v;
                $sql = str_replace(':' . $k, ':' . $nk, $sql);
            } else {
                $newBindings[$k] = $v;
            }
        }

        return [$sql, $newBindings];
    }

    /**
     * Resolve destination insert columns for copyUpsert / copyUpsertChunked.
     *
     * @return array<int,string>
     */
    private function resolveCopyInsertColumns(QueryBuilder $selectQuery, ?array $insertColumns): array
    {
        if ($insertColumns !== null) {
            if ($insertColumns === []) {
                throw new QueryException('copyUpsert(): $insertColumns cannot be empty.');
            }
            $safe = [];
            foreach ($insertColumns as $c) {
                if (!is_string($c) || trim($c) === '') {
                    throw new QueryException('copyUpsert(): $insertColumns must be non-empty strings.');
                }
                $col = Identifier::column($c);
                if (str_contains($col, '.')) {
                    throw new QueryException('copyUpsert(): destination insert column cannot include table prefix.', ['column' => $col]);
                }
                $safe[] = $col;
            }
            return $safe;
        }

        // Infer from select list
        if ($selectQuery->select === ['*']) {
            throw new QueryException("copyUpsert(): source SELECT cannot be '*'. Call select(...) or pass \$insertColumns.");
        }

        foreach ($selectQuery->select as $sel) {
            if (!is_string($sel) || trim($sel) === '') {
                throw new QueryException('copyUpsert(): invalid source select column.');
            }
            if (stripos($sel, ' AS ') !== false) {
                throw new QueryException('copyUpsert(): cannot infer insert columns when selectAs() is used; pass $insertColumns explicitly.');
            }
            if (str_contains($sel, '.')) {
                throw new QueryException('copyUpsert(): source select columns cannot be qualified when inferring insert columns.', ['column' => $sel]);
            }
        }

        return $selectQuery->select;
    }
    private function resolveFromClause(): string
    {
        if ($this->fromTable !== '') {
            return $this->buildFromClause();
        }
        if ($this->table !== '') {
            // Optional legacy fallback (if you want it)
            return $this->table;
        }
        throw new QueryException('No table set. Call from()/fromAs() or table() first.');
    }

}
