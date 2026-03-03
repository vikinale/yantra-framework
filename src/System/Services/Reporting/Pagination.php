<?php
declare(strict_types=1);

namespace System\Services\Reporting;

final class Pagination
{
    public readonly int $page;
    public readonly int $perPage;
    public readonly int $offset;
    public readonly int $limit;

    public function __construct(int $page, int $perPage)
    {
        $this->page = max(1, $page);
        $this->perPage = max(1, $perPage);
        $this->limit = $this->perPage;
        $this->offset = ($this->page - 1) * $this->perPage;
    }

    /**
     * Build pagination from already-cast params.
     * Supports page/per_page and limit/offset.
     *
     * @param array<string, mixed> $params
     */
    public static function fromParams(array $params, int $defaultPerPage = 50, int $maxPerPage = 500): self
    {
        if (isset($params['limit']) || isset($params['offset'])) {
            $limit = (int)($params['limit'] ?? $defaultPerPage);
            $offset = (int)($params['offset'] ?? 0);

            $limit = max(1, min($limit, $maxPerPage));
            $offset = max(0, $offset);

            $page = (int)floor($offset / $limit) + 1;
            return new self($page, $limit);
        }

        $page = (int)($params['page'] ?? 1);
        $perPage = (int)($params['per_page'] ?? $defaultPerPage);
        $perPage = max(1, min($perPage, $maxPerPage));
        return new self($page, $perPage);
    }

    /** @return array<string, int> */
    public function toMeta(): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'limit' => $this->limit,
            'offset' => $this->offset,
        ];
    }
}
