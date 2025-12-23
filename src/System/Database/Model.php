<?php
declare(strict_types=1);

namespace System\Database;

/**
 * Model
 *
 * A thin convenience layer above MasterModel + QueryBuilder.
 * Keeps framework generic (no app-specific behavior).
 */
class Model extends MasterModel
{
    protected string $tableName = '';

    /**
     * @param array<string,mixed> $dbConfig
     */
    public function __construct(array $dbConfig, bool $isDev = false, ?\System\Database\Support\LoggerInterface $logger = null)
    {
        parent::__construct($dbConfig, $isDev, $logger);

        if ($this->tableName !== '') {
            $this->from($this->tableName);
        }
    }

    public function setTable(string $table): static
    {
        $this->tableName = $table;
        $this->from($table);
        return $this;
    }

    public function getTable(): string
    {
        return $this->tableName !== '' ? $this->tableName : $this->table;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $clone = clone $this;
        return $clone->get();
    }

    /** @return array<string,mixed>|null */
    public function find(mixed $id): ?array
    {
        $clone = clone $this;
        $clone->where($this->primaryKey, '=', $id);
        return $clone->first();
    }

    /**
     * DataTables-style results with safe ordering (QueryBuilder validates direction now).
     *
     * @return array{data:array<int,array<string,mixed>>, total:int, filtered:int}
     */
    public function getDataTableResults(
        int $start,
        int $length,
        ?string $searchValue = null,
        ?string $orderColumn = null,
        ?string $orderDir = null,
        array $searchColumns = []
    ): array {
        $start  = max(0, $start);
        $length = max(1, $length);

        $base = clone $this;

        $total = $base->count();

        $q = clone $this;

        // Search (safe: columns validated in where())
        $sv = trim((string)$searchValue);
        if ($sv !== '' && $searchColumns !== []) {
            $first = true;
            foreach ($searchColumns as $col) {
                if (!is_string($col) || trim($col) === '') continue;
                if ($first) {
                    $q->where($col, 'LIKE', '%' . $sv . '%', 'AND');
                    $first = false;
                } else {
                    $q->orWhere($col, 'LIKE', '%' . $sv . '%');
                }
            }
        }

        $filtered = $q->count();

        // Ordering (safe: Identifier::direction() enforces ASC/DESC, column validated)
        if (is_string($orderColumn) && trim($orderColumn) !== '') {
            $q->orderBy($orderColumn, (string)$orderDir);
        }

        $data = $q->limit($length)->offset($start)->get();

        return [
            'data'     => $data,
            'total'    => $total,
            'filtered' => $filtered,
        ];
    }
}
