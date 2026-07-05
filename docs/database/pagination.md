# Pagination

The query builder paginates any query with `paginate()` (full paginator with total count) or `simplePaginate()` (lightweight previous/next paginator that skips the `COUNT(*)` query). Both return paginator objects from `System\Database\Pagination` that render links, serialize to JSON, and expose the current page slice as a `Collection`.

```php
$users = User::where('is_active', '=', 1)
    ->orderBy('name')
    ->paginate(20);

foreach ($users->items() as $user) { /* ... */ }
echo $users->links();
```

## paginate() vs simplePaginate()

```php
// LengthAwarePaginator — runs COUNT(*) + the page query
$paginator = User::query()->paginate(15);

// Paginator — fetches perPage+1 rows to detect a next page; no COUNT
$paginator = User::query()->simplePaginate(15);
```

Both share the signature `(int $perPage = 15, string $pageName = 'page', ?int $page = null)`:

- `$perPage` — items per page (minimum 1).
- `$pageName` — the query-string parameter used for the page number and generated URLs.
- `$page` — explicit current page; when `null` it is auto-detected from `$_GET[$pageName]`.

Use `simplePaginate()` for large tables or infinite-scroll UIs where the total count is not needed; use `paginate()` when you want numbered page links and totals.

## Paginator API (both classes)

`Paginator` is the base class; `LengthAwarePaginator` extends it. Shared methods:

| Method | Returns | Description |
| --- | --- | --- |
| `items()` | `Collection` | Items on the current page (a `System\Support\Collection`, not an array). |
| `currentPage()` | `int` | Current page number. |
| `perPage()` | `int` | Items per page. |
| `count()` | `int` | Number of items on *this* page. |
| `hasMorePages()` | `bool` | Whether a next page exists. |
| `hasPreviousPage()` | `bool` | Whether a previous page exists. |
| `hasPages()` | `bool` | Whether pagination is needed at all. |
| `onFirstPage()` | `bool` | True when on page 1. |
| `firstItem()` / `lastItem()` | `?int` | 1-based index of the first/last item in the slice (`null` when empty). |
| `url(int $page)` | `string` | URL for a page, preserving other query parameters. |
| `previousPageUrl()` / `nextPageUrl()` | `?string` | URL for the adjacent page, or `null`. |
| `getPageName()` | `string` | The page query parameter name. |
| `links()` | `string` | Ready-made HTML (Bootstrap-compatible markup). |
| `toArray()` / `jsonSerialize()` | `array` | Serializable payload (`data`, `per_page`, `current_page`, URLs, ...). |

### LengthAwarePaginator additions

| Method | Returns | Description |
| --- | --- | --- |
| `total()` | `int` | Total items across all pages. |
| `lastPage()` | `int` | Last page number. |
| `onLastPage()` | `bool` | True when on the last page. |

`LengthAwarePaginator::links()` renders numbered page links with a window around the current page (`1 ... 4 5 6 ... 20`), while the base `Paginator::links()` renders only Previous/Next. Its `toArray()` additionally includes `total`, `last_page`, `first_page_url`, and `last_page_url`.

## JSON APIs

Paginators are `JsonSerializable`, so you can return them directly in a JSON response:

```php
public function index(Request $request, Response $response): Response
{
    $posts = Post::where('published', '=', 1)->paginate(10);

    return $response->json($posts);   // {"data":[...],"total":42,"per_page":10,...}
}
```

## Rendering pagination in a view

Pass the paginator to your view and either call `links()` for instant markup, or build your own:

```php
// Controller
$users = User::where('is_active', '=', 1)->orderBy('name')->paginate(20);
return $this->view('users/index', ['users' => $users]);
```

```php
<!-- App/Views/users/index.php -->
<table>
    <thead>
        <tr><th>#</th><th>Name</th><th>Email</th></tr>
    </thead>
    <tbody>
        <?php foreach ($users->items() as $i => $user): ?>
        <tr>
            <td><?= $users->firstItem() + $i ?></td>
            <td><?= htmlspecialchars($user['name']) ?></td>
            <td><?= htmlspecialchars($user['email']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<p>
    Showing <?= $users->firstItem() ?>–<?= $users->lastItem() ?>
    of <?= $users->total() ?> users
    (page <?= $users->currentPage() ?> of <?= $users->lastPage() ?>)
</p>

<!-- Option 1: built-in Bootstrap-compatible links -->
<?= $users->links() ?>

<!-- Option 2: custom markup with verified methods -->
<?php if ($users->hasPages()): ?>
<nav class="pager">
    <?php if ($users->hasPreviousPage()): ?>
        <a href="<?= htmlspecialchars($users->previousPageUrl()) ?>">&laquo; Previous</a>
    <?php endif; ?>

    <span><?= $users->currentPage() ?> / <?= $users->lastPage() ?></span>

    <?php if ($users->hasMorePages()): ?>
        <a href="<?= htmlspecialchars($users->nextPageUrl()) ?>">Next &raquo;</a>
    <?php endif; ?>
</nav>
<?php endif; ?>
```

::: warning Gotchas
- `items()` returns a `Collection`, not a plain array — call `->all()` or `->toArray()` on it when you need an array.
- Rows in the paginator come from the query builder's `get()`, so they are **raw associative arrays**, not model instances (see [Models](/database/models)).
- `url()` merges the current `$_GET` parameters, which keeps filters/sorting in the links — but also anything else in the query string.
- `total()`, `lastPage()`, and `onLastPage()` exist only on `LengthAwarePaginator` (i.e. `paginate()`); calling them on a `simplePaginate()` result is an error.
- The current page is clamped: values below 1 become 1, and `LengthAwarePaginator` also clamps to the last page.
:::

## Related

- [Query Builder](/database/query-builder)
- [Models & ORM](/database/models)
- [Collections](/features/collections)
- [Views](/essentials/views)
- [Responses](/essentials/responses)
