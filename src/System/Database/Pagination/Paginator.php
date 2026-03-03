<?php
declare(strict_types=1);

namespace System\Database\Pagination;

use System\Support\Collection;

/**
 * Paginator — Simple paginator (no total count).
 *
 * Lightweight pagination that only knows if there are more pages.
 * Does not run a COUNT(*) query, making it faster for large datasets.
 *
 * Usage:
 *   $users = User::where('active', '=', 1)->simplePaginate(20);
 *   $users->items();        // Collection of current page items
 *   $users->hasMorePages(); // true if there's a next page
 *   $users->currentPage();  // current page number
 */
class Paginator implements \JsonSerializable
{
    protected Collection $items;
    protected int $perPage;
    protected int $currentPage;
    protected bool $hasMore;
    protected string $pageName;
    protected string $path;

    /**
     * @param array|Collection $items Items for the current page.
     * @param int $perPage Items per page.
     * @param int|null $currentPage Current page (auto-detected from $_GET if null).
     * @param string $pageName Query parameter name for the page.
     */
    public function __construct(
        array|Collection $items,
        int $perPage,
        ?int $currentPage = null,
        string $pageName = 'page'
    ) {
        $this->perPage     = max(1, $perPage);
        $this->pageName    = $pageName;
        $this->currentPage = $this->resolveCurrentPage($currentPage);
        $this->path        = $this->resolveCurrentPath();

        // We fetched perPage+1 items; if we got more than perPage, there are more pages
        $itemsArray = $items instanceof Collection ? $items->all() : $items;

        $this->hasMore = count($itemsArray) > $this->perPage;

        // Trim to perPage items
        $this->items = new Collection(array_slice($itemsArray, 0, $this->perPage));
    }

    /**
     * Get the items for the current page.
     */
    public function items(): Collection
    {
        return $this->items;
    }

    /**
     * Get the current page number.
     */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Get the number of items per page.
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Determine if there are more items after this page.
     */
    public function hasMorePages(): bool
    {
        return $this->hasMore;
    }

    /**
     * Determine if there are pages before this page.
     */
    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    /**
     * Get the number of the first item in the slice.
     */
    public function firstItem(): ?int
    {
        if ($this->items->isEmpty()) {
            return null;
        }
        return ($this->currentPage - 1) * $this->perPage + 1;
    }

    /**
     * Get the number of the last item in the slice.
     */
    public function lastItem(): ?int
    {
        $first = $this->firstItem();
        if ($first === null) {
            return null;
        }
        return $first + $this->items->count() - 1;
    }

    /**
     * Get the URL for a given page number.
     */
    public function url(int $page): string
    {
        $page = max(1, $page);

        $query = $_GET;
        $query[$this->pageName] = $page;

        return $this->path . '?' . http_build_query($query);
    }

    /**
     * Get the URL for the previous page, or null if on the first page.
     */
    public function previousPageUrl(): ?string
    {
        return $this->hasPreviousPage() ? $this->url($this->currentPage - 1) : null;
    }

    /**
     * Get the URL for the next page, or null if there are no more pages.
     */
    public function nextPageUrl(): ?string
    {
        return $this->hasMorePages() ? $this->url($this->currentPage + 1) : null;
    }

    /**
     * Get the page name (query parameter).
     */
    public function getPageName(): string
    {
        return $this->pageName;
    }

    /**
     * Determine if the paginator is on the first page.
     */
    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    /**
     * Get the number of items on the current page.
     */
    public function count(): int
    {
        return $this->items->count();
    }

    /**
     * Determine if there are enough items to split into pages.
     */
    public function hasPages(): bool
    {
        return $this->currentPage !== 1 || $this->hasMorePages();
    }

    /**
     * Render simple pagination HTML (Previous / Next).
     */
    public function links(string $view = ''): string
    {
        if (!$this->hasPages()) {
            return '';
        }

        $html = '<nav aria-label="Pagination"><ul class="pagination">';

        // Previous
        if ($this->hasPreviousPage()) {
            $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($this->previousPageUrl() ?? '', ENT_QUOTES, 'UTF-8') . '">&laquo; Previous</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">&laquo; Previous</span></li>';
        }

        // Next
        if ($this->hasMorePages()) {
            $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($this->nextPageUrl() ?? '', ENT_QUOTES, 'UTF-8') . '">Next &raquo;</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">Next &raquo;</span></li>';
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
            'per_page'      => $this->perPage,
            'current_page'  => $this->currentPage,
            'first_item'    => $this->firstItem(),
            'last_item'     => $this->lastItem(),
            'next_page_url' => $this->nextPageUrl(),
            'prev_page_url' => $this->previousPageUrl(),
            'path'          => $this->path,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /* ========================================================================
     * Internal
     * ======================================================================== */

    protected function resolveCurrentPage(?int $page): int
    {
        if ($page !== null) {
            return max(1, $page);
        }

        $fromQuery = $_GET[$this->pageName] ?? null;

        if ($fromQuery !== null && is_numeric($fromQuery)) {
            return max(1, (int) $fromQuery);
        }

        return 1;
    }

    protected function resolveCurrentPath(): string
    {
        return strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
    }
}
