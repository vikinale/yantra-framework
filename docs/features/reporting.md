# Reporting

Yantra ships a UI- and database-agnostic reporting subsystem under `System\Services\Reporting`. You describe a report once — its metadata, a typed parameter schema, and a runner closure that returns rows — and register it. The `ReportManager` then handles the repetitive parts of every report endpoint: casting raw request input to typed parameters, validating it against the schema, running the report with timing/metadata, and (optionally) authorizing access. Results come back as a neutral `ReportResult` that you can render as an HTML table, stream to CSV, or serialize to JSON.

```php
use System\Services\Reporting\Definition\CallableReport;
use System\Services\Reporting\Definition\ReportMetadata;
use System\Services\Reporting\Params\ParamDefinition;
use System\Services\Reporting\Params\ParamSchema;
use System\Services\Reporting\Params\ParamType;
use System\Services\Reporting\ReportContext;
use System\Services\Reporting\ReportManager;
use System\Services\Reporting\ReportRegistry;
use System\Services\Reporting\ReportResult;

$registry = new ReportRegistry();

$registry->add(new CallableReport(
    metadata: new ReportMetadata(key: 'sales.monthly', title: 'Monthly Sales'),
    schema: new ParamSchema([
        new ParamDefinition('month', ParamType::DATE, required: true),
    ]),
    runner: function (ReportContext $ctx, array $params): ReportResult {
        // $params['month'] is a DateTimeImmutable — typed and validated
        $rows = [
            ['product' => 'Widget', 'revenue' => 1200],
            ['product' => 'Gadget', 'revenue' => 900],
        ];

        return new ReportResult(
            columns: [
                ['key' => 'product', 'label' => 'Product'],
                ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'money'],
            ],
            rows: $rows,
            summary: ['total' => 2100],
        );
    }
));

$manager = new ReportManager($registry);

$result = $manager->run('sales.monthly', new ReportContext(), ['month' => '2026-06-01']);
```

## Defining a report

Every report is a `ReportDefinitionInterface` with three parts: `metadata()`, `schema()`, and `run()`. For the common case, `CallableReport` wraps a closure so you don't need a dedicated class.

### Metadata

`ReportMetadata` is an immutable descriptor. Only `key` and `title` are required:

```php
new ReportMetadata(
    key: 'sales.monthly',           // unique registry key (required, non-empty)
    title: 'Monthly Sales',         // human label (required, non-empty)
    description: 'Revenue by product for a given month',
    category: 'Finance',
    tags: ['sales', 'finance'],
    permissions: ['reports.view.sales'],  // consumed by your authorizer
    cacheTtlSeconds: 300,           // surfaced in result meta; caching is your responsibility
    version: '2',
);
```

`metadata()->toArray()` gives you a JSON-ready descriptor — handy for a "list available reports" endpoint (see [`list()`](#listing-reports)).

### Parameter schema

A `ParamSchema` is an ordered set of `ParamDefinition`s. Each definition declares a name, a `ParamType`, and optional constraints that the caster and validator enforce automatically:

```php
new ParamSchema([
    new ParamDefinition('status', ParamType::ENUM, required: true, allowed: ['open', 'closed']),
    new ParamDefinition('min_total', ParamType::FLOAT, default: 0.0, min: 0),
    new ParamDefinition('search', ParamType::STRING, minLength: 2, maxLength: 100),
    new ParamDefinition('tags', ParamType::ARRAY, itemsType: ParamType::STRING),
]);
```

Supported `ParamType` constants: `STRING`, `INT`, `FLOAT`, `BOOL`, `DATE` (a `DateTimeImmutable` at 00:00 in the context timezone), `DATETIME`, `ENUM` (requires `allowed`), `ARRAY` (optionally typed via `itemsType`), and `JSON`. Constraints available per definition: `required`, `default`, `allowed`, `min`/`max`, `minLength`/`maxLength`, `pattern`, plus custom `caster` and `validators` callables.

Add the standard `page` / `per_page` parameters with `withPagination()`:

```php
$schema = (new ParamSchema([/* ... */]))->withPagination(defaultPerPage: 50, maxPerPage: 500);
```

### The runner and its result

The runner receives the `ReportContext` and the **typed, validated** `$params` array, and must return a `ReportResult`:

```php
new ReportResult(
    columns: [['key' => 'product', 'label' => 'Product', 'type' => 'string']],
    rows: $rows,          // any iterable — arrays or a generator for streaming
    summary: ['total' => 2100],
    meta: [],             // ReportManager augments this (see below)
);
```

`rows` is declared `iterable`, so you can `yield` rows from a generator to stream large result sets instead of building a giant array. `ReportResult` is immutable (readonly properties).

## The registry

`ReportRegistry` is a keyed collection of report definitions:

```php
$registry = new ReportRegistry();
$registry->add($report);                 // throws if the key is already registered
$registry->addMany([$a, $b, $c]);
$registry->has('sales.monthly');         // bool
$registry->get('sales.monthly');         // throws ReportNotFoundException if missing
$registry->all();                        // list<ReportDefinitionInterface>
$registry->catalog();                    // list<ReportMetadata>
```

Wire the registry (and the manager) through the [DI container](/features/container) so controllers can depend on a `ReportManager`.

## Running reports

`ReportManager` orchestrates a run. Construct it with the registry and, optionally, a custom caster, validator, and authorizer:

```php
use System\Services\Reporting\Params\ParamCaster;
use System\Services\Reporting\Params\ParamValidator;

$manager = new ReportManager(
    registry: $registry,
    caster: new ParamCaster(),          // defaults are fine
    validator: new ParamValidator(),
    authorizer: new MyAuthorizer(),     // optional — see below
);
```

### `run()`

```php
$result = $manager->run(string $key, ReportContext $ctx, array $input): ReportResult;
```

`run()` performs the full pipeline:

1. Looks up the report (`ReportNotFoundException` if missing).
2. If an authorizer is set, calls `canRun()` (throws `RuntimeException` when denied).
3. **Casts** raw `$input` to typed values via the schema.
4. **Validates** the typed values (`validateOrThrow()` raises `ReportValidationException` on failure).
5. Runs the report, timing it. Any exception from the runner is wrapped in `ReportExecutionException`.
6. Returns a new `ReportResult` with the runner's `meta` augmented by `report_key`, `report_version`, `elapsed_ms`, and `cache_ttl_seconds` (when set on the metadata).

Because casting happens first, your runner always receives real PHP types — a `DATE` param is a `DateTimeImmutable`, an `INT` is an `int`, an `ARRAY` is an array — never raw request strings.

### Listing reports

```php
$manager->list(ReportContext $ctx): array;
```

Returns each report's `metadata()->toArray()`, filtered through the authorizer's `canView()` when one is configured. Use it to build a report picker UI.

## Authorization

Authorization is entirely application-defined. Implement `ReportAuthorizerInterface` and pass it to the manager; the `permissions` on each report's metadata and the `actor` on the `ReportContext` are yours to interpret:

```php
use System\Services\Reporting\Security\ReportAuthorizerInterface;
use System\Services\Reporting\Definition\ReportDefinitionInterface;
use System\Services\Reporting\ReportContext;

final class RoleReportAuthorizer implements ReportAuthorizerInterface
{
    public function canView(ReportContext $ctx, ReportDefinitionInterface $report): bool
    {
        return $this->actorHasAny($ctx->actor(), $report->metadata()->permissions);
    }

    public function canRun(ReportContext $ctx, ReportDefinitionInterface $report): bool
    {
        return $this->canView($ctx, $report);
    }

    private function actorHasAny(mixed $actor, array $permissions): bool
    {
        if ($permissions === []) return true;
        // ... your role/permission check
        return true;
    }
}
```

## The report context

`ReportContext` carries request-scoped, DB/route-agnostic data into a runner — timezone, locale, the acting user, and an arbitrary attribute bag for injecting repositories or clients:

```php
$ctx = new ReportContext(
    timezone: 'Asia/Kolkata',
    locale: 'en',
    actor: $currentUser,
    attributes: ['db' => $connection],
);

$ctx->timezone();          // 'Asia/Kolkata'  — also used to interpret DATE/DATETIME params
$ctx->actor();             // $currentUser
$ctx->get('db');           // repository/connection you injected
$ctx = $ctx->with('extra', $value);  // returns a clone; ReportContext is immutable
```

## Exporting results

A `ReportResult` is presentation-neutral. Two exporters ship in `System\Services\Reporting\Export`:

### CSV

`CsvExporter::writeToStream()` streams the result to any open stream, using the `columns` for the header row:

```php
use System\Services\Reporting\Export\CsvExporter;

// In a controller — stream a download straight to output
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="monthly-sales.csv"');

$out = fopen('php://output', 'w');
(new CsvExporter())->writeToStream($result, $out, [
    'delimiter' => ',',
    'include_header' => true,
]);
fclose($out);
```

Values are stringified sensibly: `null` → empty, booleans → `1`/`0`, `DateTimeInterface` → ISO-8601, arrays/objects → JSON.

### JSON

`JsonExporter::toArray()` returns a JSON-encodable structure and caps row count to guard against accidental huge payloads:

```php
use System\Services\Reporting\Export\JsonExporter;

$payload = (new JsonExporter())->toArray($result, maxRows: 1000);
// ['columns' => [...], 'rows' => [...], 'summary' => ..., 'meta' => [... 'rows_truncated' => bool]]

return $this->success($payload);
```

## Exceptions

| Exception | Thrown when |
| --- | --- |
| `ReportNotFoundException` | The requested key isn't in the registry. |
| `ReportValidationException` | Input fails schema validation (carries per-field errors). |
| `ReportExecutionException` | The runner throws — the original error is wrapped as the cause. |

::: warning Gotchas
- **`run()` casts before it validates.** Your runner always gets typed params; don't re-parse request strings yourself.
- **`cacheTtlSeconds` does not cache anything.** It's surfaced in `meta` for *you* to act on — wrap `run()` with the [Cache](/features/cache) `remember()` pattern if you want caching.
- **`rows` can be a generator.** Prefer `yield` for large reports; but note the `JsonExporter` truncates at `maxRows` and `ReportResult` can only be iterated once if you back it with a generator.
- **Authorization is opt-in.** Without a `ReportAuthorizerInterface`, every registered report is listable and runnable.
- **`ReportRegistry::add()` throws on duplicate keys** — register each report exactly once (watch out for double-loading a definitions file).
:::

## Related

- [Imports](/features/imports) — the inbound counterpart (CSV → your system)
- [Cache](/features/cache) — cache expensive report runs with `remember()`
- [Container](/features/container) — wire the registry and manager as services
- [Responses](/essentials/responses) — stream CSV/JSON downloads
