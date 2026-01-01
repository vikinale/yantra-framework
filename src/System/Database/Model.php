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
    /** @var array<int,string> */
    protected array $fillable = [];

    protected bool $timestamps = true;
    protected string $createdAt = 'created_at';
    protected string $updatedAt = 'updated_at';

    /**
     * @param array<string,mixed> $dbConfig
     */
    public function __construct(?array $dbConfig, ?\System\Database\Support\LoggerInterface $logger = null)
    {
        parent::__construct($dbConfig, $logger);
                
        if ($this->tableName !== '') {
            $this->from($this->tableName);
        }
    }

    /**
     * Filter only allowed columns (protects against mass assignment).
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function onlyFillable(array $data): array
    {
        if (empty($this->fillable)) return $data;

        $out = [];
        foreach ($this->fillable as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }
        return $out;
    }

    /**
     * Create record (returns inserted id).
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $data = $this->onlyFillable($data);

        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');
            $data[$this->createdAt] = $data[$this->createdAt] ?? $now;
            $data[$this->updatedAt] = $data[$this->updatedAt] ?? $now;
        }

        $this->insert($data);
        return (int)$this->lastInsertId();
    }

    /**
     * Update by primary key.
     * @param array<string,mixed> $data
     */
    public function updateById(int $id, array $data): bool
    {
        $data = $this->onlyFillable($data);

        if ($this->timestamps) {
            $data[$this->updatedAt] = date('Y-m-d H:i:s');
        }

        $clone = clone $this;
        $clone->where($this->getPrimaryKey(), '=', $id);
        return $clone->update($data) > 0;
    }

    public function deleteById(int $id): bool
    {
        $clone = clone $this;
        $clone->where($this->getPrimaryKey(), '=', $id);
        return $clone->delete() > 0;
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
