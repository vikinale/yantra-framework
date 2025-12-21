<?php

namespace System\Database;

use Exception;
use PDO;
use PDOStatement;

/**
 * Simple active-record style Model built on top of MasterModel / QueryBuilder.
 */
class Model extends MasterModel
{
    public function __construct(string $table, string $primaryKey)
    {
        parent::__construct($table, $primaryKey);
        // parent already calls connect()
    }

    /**
     * Get a record by its primary key as an object (or null).
     *
     * @throws Exception
     */
    public function get(int|string $id): ?object
    {
        $res = (clone $this)->query()->where($this->primaryKey, '=', $id)->getResult(PDO::FETCH_OBJ);
        return $res ?: null;
    }

    /**
     * Get a record by its primary key and return as associative object (alias).
     *
     * @throws Exception
     */
    public function getObject(int|string $id): ?object
    {
        return $this->get($id);
    }

    /**
     * Insert a new record.
     *
     * @throws Exception
     */
    public function insert(?array $data): int
    {
        // Use a clone when building the insert query so we don't mutate caller.
        $builder = clone $this;
        $builder->_query('insert');
        try {
            if ($data === null) {
                $count = $builder->executeQuery()->rowCount();
            } else {
                $count = $builder->data($data)->executeQuery()->rowCount();
            }

            if ($count > 0) {
                // lastInsertId() returns string from MasterModel/Database; cast to int for convenience
                return (int)$this->lastInsertId();
            }
            return 0;
        } catch (Exception $e) {
            // include SQL for easier debugging
            $sql = method_exists($builder, 'getSql') ? $builder->getSql() : '(sql unavailable)';
            $bindings = method_exists($builder, 'getBindings') ? var_export($builder->getBindings(), true) : '';
            throw new Exception("DB Error: " . $e->getMessage() . " SQL: {$sql} Bindings: {$bindings}");
        }
    }

    /**
     * Update an existing record.
     *
     * @throws Exception
     */
    public function update(?array $data): int
    {
        $builder = clone $this;
        if ($data === null) {
            return $builder->_query('update')->executeQuery()->rowCount();
        }
        return $builder->_query('update')->data($data)->executeQuery()->rowCount();
    }

    /**
     * Save a record (insert or update) using ON DUPLICATE KEY UPDATE semantics.
     *
     * @throws Exception
     */
    public function save(array $data, array $updateColumns): int
    {
        $builder = clone $this;
        $builder = $builder->query('insert')
            ->data($data)
            ->onDuplicateKeyUpdate($updateColumns);
            error_log('Updating user meta: ' . $builder->getSql());
        return $builder->executeQuery()->rowCount();
    }

    /**
     * Insert multiple records in one query.
     *
     * Usage:
     *   // prepare mode
     *   $this->batchInsert(null, true);
     *   // execute mode
     *   $this->batchInsert($rows, true);
     *
     * @param array|null $data
     * @param bool $ignore
     * @return mixed
     * @throws Exception
     */
    public function batchInsert(?array $data, bool $ignore = true): self|int
    {
        if ($data === null) {
            $this->query('batchInsert');
            $this->ignore($ignore);
            return $this;
        }
        $builder = clone $this;
        return $builder->query('batchInsert')->data($data)->executeQuery()->rowCount();
    }

    /**
     * Delete a record by its primary key (or delete by where if $id is null).
     *
     * @throws Exception
     */
    public function delete(int|string|null $id = null): int
    {
        if ($id === null) {
            return $this->_query('delete')->executeQuery()->rowCount();
        }

        return $this->query('delete')
            ->where($this->primaryKey, '=', $id)
            ->executeQuery()->rowCount();
    }


    /**
     * Soft delete a record by its primary key (or soft-delete by where if $id is null).
     *
     * @throws Exception
     */
    public function softDelete(int|string|null $id = null): int
    {
        if ($id === null) {
            return $this->_query('soft_delete')->executeQuery()->rowCount();
        }
        return $this->query('soft_delete')
            ->where($this->primaryKey, "=", $id)
            ->executeQuery()->rowCount();
    }

    /**
     * Get datatable-style results with server-side search / ordering.
     *
     * This method clones the builder when computing totals so the original query state is preserved.
     *
     * @throws Exception
     */
    public function getDataTableResults(array $columns, int $start, int $length, array $order = [], array $search = []): array
    {
        // operate on a clone to avoid polluting $this
        $query = clone $this;

        // Handling search filters
        if (!empty($search)) {
            foreach ($search as $column => $value) {
                if (trim($value) !== '' && isset($columns[$column])) {
                    $query->where($columns[$column], 'LIKE', '%' . $value . '%');
                }
            }
        }

        // Handling ordering
        if (!empty($order)) {
            foreach ($order as $column => $direction) {
                if (isset($columns[$column])) {
                    $query->orderBy($columns[$column], $direction);
                }
            }
        }

        // Compute totalRecords using a cloned builder (so we don't lose $query state)
        $countQuery = clone $query;
        $countQuery->select('COUNT(*) AS c')->limit(1)->offset(0);
        $countRow = $countQuery->getResult(PDO::FETCH_ASSOC);
        $totalRecords = isset($countRow['c']) ? (int)$countRow['c'] : 0;

        // Handling pagination
        $query->limit($length)->offset($start);

        // Execute the query and return the results
        $statement = $query->executeQuery();
        $results = $statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'records' => $results,
            'totalRecords' => $totalRecords
        ];
    }

    /**
     * Helper to build a select list.
     */
    public function getSelectList(string $valueColumn, string $displayColumn, $selected = null): array
    {
        $selectList = [];
        try {
            $list = (clone $this)->select("$valueColumn, $displayColumn")->getResults(PDO::FETCH_OBJ);
            foreach ($list as $item) {
                $selectList[] = ['value' => $item->{$valueColumn}, 'display' => $item->{$displayColumn}];
            }
        } catch (Exception $e) {
            // swallow and return empty list
        }
        return $selectList;
    }

    /**
     * Retrieve paginated results (classic offset pagination).
     *
     * @throws \Exception
     */
    public function getPage(?int $page,?int $perPage,?array $search,?array $order): array {
        // Normalise pagination params
        $page    = max(1, $page);
        $perPage = max(1, $perPage);

        $offset = ($page - 1) * $perPage;

        // Start from a base query so $this stays untouched
        $baseQuery = clone $this;

        // Apply search filters (on the base query)
        if (!empty($search)) {
            foreach ($search as $column => $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $baseQuery->where($column, 'LIKE', '%' . $value . '%');
                }
            }
        }

        // ---------- 1) Total count query (no ORDER BY, LIMIT, GROUP BY) ----------
        $countQuery = clone $baseQuery;

        $countRow = $countQuery
            ->select('COUNT(*) AS c')
            ->clearOrderBy()
            ->clearGroupBy()
            ->clearLimitOffset()
            ->getResult(\PDO::FETCH_ASSOC);

        $totalRecords = isset($countRow['c']) ? (int) $countRow['c'] : 0;

        // If nothing matches, we can early-return without hitting DB again
        if ($totalRecords === 0) {
            return [
                'records'      => [],
                'totalRecords' => 0,
                'currentPage'  => $page,
                'perPage'      => $perPage,
                'totalPages'   => 0,
            ];
        }

        // ---------- 2) Data query with ORDER BY + LIMIT/OFFSET ----------
        $dataQuery = clone $baseQuery;

        // Apply ordering only on data query
        if (!empty($order)) {
            foreach ($order as $column => $direction) {
                $direction = strtoupper((string) $direction) === 'DESC' ? 'DESC' : 'ASC';
                $dataQuery->orderBy($column, $direction);
            }
        }

        $results = $dataQuery
            ->limit($perPage)
            ->offset($offset)
            ->getResults(\PDO::FETCH_ASSOC);

        return [
            'records'      => $results,
            'totalRecords' => $totalRecords,
            'currentPage'  => $page,
            'perPage'      => $perPage,
            'totalPages'   => $perPage > 0 ? (int) ceil($totalRecords / $perPage) : 0,
        ];
    }


    /**
     * Get the sum of a column.
     *
     * @throws Exception
     */
    public function sum(string $column): float
    {
        $row = (clone $this)->selectRaw("SUM(`$column`) as total")->getResult(PDO::FETCH_ASSOC);
        return isset($row['total']) ? (float)$row['total'] : 0.0;
    }

    /**
     * Get the average of a column.
     */
    public function avg(string $column): float
    {
        $row = (clone $this)->selectRaw("AVG(`$column`) as average")->getResult(PDO::FETCH_ASSOC);
        return isset($row['average']) ? (float)$row['average'] : 0.0;
    }

    /**
     * Get the minimum value of a column.
     */
    public function min(string $column): mixed
    {
        $row = (clone $this)->selectRaw("MIN(`$column`) as min_value")->getResult(PDO::FETCH_ASSOC);
        return $row['min_value'] ?? null;
    }

    /**
     * Get the maximum value of a column.
     */
    public function max(string $column): mixed
    {
        $row = (clone $this)->selectRaw("MAX(`$column`) as max_value")->getResult(PDO::FETCH_ASSOC);
        return $row['max_value'] ?? null;
    }
}
