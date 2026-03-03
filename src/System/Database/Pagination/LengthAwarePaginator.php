<?php
declare(strict_types=1);

namespace System\Database\Pagination;

use System\Support\Collection;

/**
 * LengthAwarePaginator — Full paginator with total count.
 *
 * Extends the simple Paginator with total item count, last page calculation,
 * and numbered page links. Requires one extra COUNT(*) query.
 *
 * Usage:
 *   $users = User::where('active', '=', 1)->paginate(20);
 *   $users->items()       // Collection of current page items
 *   $users->total()       // 98
 *   $users->lastPage()    // 5
 *   $users->currentPage() // 2
 *   $users->links()       // HTML pagination with page numbers
 */
class LengthAwarePaginator extends Paginator
{
    protected int $total;
    protected int $lastPage;

    /**
     * @param array|Collection $items Items for the current page.
     * @param int $total Total number of items across all pages.
     * @param int $perPage Items per page.
     * @param int|null $currentPage Current page (auto-detected from $_GET if null).
     * @param string $pageName Query parameter name for the page.
     */
    public function __construct(
        array|Collection $items,
        int $total,
        int $perPage,
        ?int $currentPage = null,
        string $pageName = 'page'
    ) {
        $this->total    = max(0, $total);
        $this->perPage  = max(1, $perPage);
        $this->lastPage = max(1, (int) ceil($this->total / $this->perPage));
        $this->pageName = $pageName;

        $this->currentPage = min($this->resolveCurrentPage($currentPage), $this->lastPage);
        $this->path        = $this->resolveCurrentPath();

        $itemsArray = $items instanceof Collection ? $items->all() : $items;
        $this->items = new Collection($itemsArray);

        // hasMore is determined by total
        $this->hasMore = $this->currentPage < $this->lastPage;
    }

    /**
     * Get the total number of items across all pages.
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Get the last page number.
     */
    public function lastPage(): int
    {
        return $this->lastPage;
    }

    /**
     * Determine if the paginator is on the last page.
     */
    public function onLastPage(): bool
    {
        return $this->currentPage >= $this->lastPage;
    }

    /**
     * Render numbered pagination HTML (Bootstrap-compatible).
     */
    public function links(string $view = ''): string
    {
        if (!$this->hasPages()) {
            return '';
        }

        $html = '<nav aria-label="Pagination"><ul class="pagination">';

        // Previous
        if ($this->hasPreviousPage()) {
            $html .= '<li class="page-item"><a class="page-link" href="'
                . htmlspecialchars($this->previousPageUrl() ?? '', ENT_QUOTES, 'UTF-8')
                . '">&laquo;</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
        }

        // Page numbers (window of pages around current)
        $window = $this->getUrlWindow();

        foreach ($window as $page) {
            if ($page === '...') {
                $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            } elseif ((int) $page === $this->currentPage) {
                $html .= '<li class="page-item active" aria-current="page"><span class="page-link">'
                    . $page . '</span></li>';
            } else {
                $html .= '<li class="page-item"><a class="page-link" href="'
                    . htmlspecialchars($this->url((int) $page), ENT_QUOTES, 'UTF-8')
                    . '">' . $page . '</a></li>';
            }
        }

        // Next
        if ($this->hasMorePages()) {
            $html .= '<li class="page-item"><a class="page-link" href="'
                . htmlspecialchars($this->nextPageUrl() ?? '', ENT_QUOTES, 'UTF-8')
                . '">&raquo;</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
        }

        $html .= '</ul></nav>';

        return $html;
    }

    /**
     * Convert the paginator to an array.
     */
    public function toArray(): array
    {
        return [
            'data'          => $this->items->toArray(),
            'total'         => $this->total,
            'per_page'      => $this->perPage,
            'current_page'  => $this->currentPage,
            'last_page'     => $this->lastPage,
            'first_item'    => $this->firstItem(),
            'last_item'     => $this->lastItem(),
            'from'          => $this->firstItem(),
            'to'            => $this->lastItem(),
            'next_page_url' => $this->nextPageUrl(),
            'prev_page_url' => $this->previousPageUrl(),
            'first_page_url' => $this->url(1),
            'last_page_url'  => $this->url($this->lastPage),
            'path'          => $this->path,
        ];
    }

    /**
     * Get the window of page numbers for display.
     *
     * Returns array of page numbers and '...' for gaps.
     * Example: [1, '...', 4, 5, 6, 7, 8, '...', 20]
     *
     * @param int $onEachSide Pages to show on each side of current page.
     * @return array<int, int|string>
     */
    protected function getUrlWindow(int $onEachSide = 3): array
    {
        $lastPage = $this->lastPage;

        // If total pages is small, show all
        if ($lastPage <= ($onEachSide * 2) + 3) {
            return range(1, $lastPage);
        }

        $pages = [];

        // Always show first page
        $pages[] = 1;

        // Start of window
        $start = max(2, $this->currentPage - $onEachSide);
        $end   = min($lastPage - 1, $this->currentPage + $onEachSide);

        // Adjust window edges
        if ($start > 2) {
            $pages[] = '...';
        }

        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }

        if ($end < $lastPage - 1) {
            $pages[] = '...';
        }

        // Always show last page
        $pages[] = $lastPage;

        return $pages;
    }

    /**
     * Determine if there are enough items to split into pages.
     */
    public function hasPages(): bool
    {
        return $this->total > $this->perPage;
    }
}
