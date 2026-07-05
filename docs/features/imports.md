# Imports

Yantra's import subsystem (`System\Services\Imports`) turns a CSV file into validated, processed rows in your system — with per-row validation, progress tracking, and **rollback** support. You describe an import once as an `ImportDefinition` (how to map columns, how to validate each row, what to do with a good row, and how to undo it), then drive it through the `ImportManager`. State and errors are persisted through a swappable store, so a half-finished import can be inspected or reversed.

```php
use System\Services\Imports\ImportManager;
use System\Services\Imports\Stores\FileImportStore;

$manager = new ImportManager(new FileImportStore(storage_path('imports')));

// 1. Register the file → get an import id
$state = $manager->create('/path/to/customers.csv', meta: ['uploaded_by' => 42]);

// 2. Process it against a definition
$state = $manager->runInline($definition, $state->id);

echo "{$state->processedRows} ok, {$state->failedRows} failed";
```

## The import definition

An `ImportDefinition` is an immutable description of *how* to import a file. You build it once and reuse it for every file of that shape:

```php
use System\Services\Imports\ImportDefinition;
use System\Services\Imports\Mapping\ColumnMap;

$definition = new ImportDefinition(
    name: 'customers',
    mapping: new ColumnMap([
        'email' => 'Email Address',   // field => CSV column header
        'name'  => 'Full Name',
        'plan'  => 'Plan',
    ]),
    validators: [new CustomerRowValidator()],   // RowValidatorInterface[]
    processor: new CustomerRowProcessor(),       // RowProcessorInterface
    rollbackHandler: new CustomerRollbackHandler(), // optional RollbackHandlerInterface
    chunkSize: 500,
);
```

### Column mapping

`ColumnMap` maps your internal field names to CSV column headers. It's **case-insensitive by default**, so `'Email Address'` matches a header of `email address`:

```php
$mapping = new ColumnMap(
    map: ['email' => 'Email', 'name' => 'Name'],
    caseInsensitive: true,
);

// Given header ['Email', 'Name'] and row ['a@b.com', 'Alice']:
$mapping->apply($header, $row);   // ['email' => 'a@b.com', 'name' => 'Alice']
```

A mapped field whose column is missing from the header resolves to `null`, so your validator can catch it.

### Row validators

Each validator implements `RowValidatorInterface` and receives the **mapped** row (field => value). Return `RowValidationResult::ok()` to pass, or `RowValidationResult::fail([...])` with one or more `ImportError`s:

```php
use System\Services\Imports\Contracts\RowValidatorInterface;
use System\Services\Imports\Model\ImportError;
use System\Services\Imports\Model\RowValidationResult;

final class CustomerRowValidator implements RowValidatorInterface
{
    public function validate(array $row): RowValidationResult
    {
        $errors = [];

        if (empty($row['email']) || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            // rowNumber is filled in by the manager; 0 is fine here
            $errors[] = new ImportError(0, 'email', 'Invalid email address');
        }
        if (empty($row['name'])) {
            $errors[] = new ImportError(0, 'name', 'Name is required');
        }

        return $errors === [] ? RowValidationResult::ok() : RowValidationResult::fail($errors);
    }
}
```

Validators run in order. **The first failing validator stops that row** — its errors are recorded against the CSV line number, `failedRows` is incremented, and the manager moves to the next row without calling the processor. You can reuse the framework [Validator](/essentials/validation) inside a row validator if you want its rule set.

### Row processor

The processor implements `RowProcessorInterface` and does the actual work for a valid row — insert a model, call an API, whatever. It returns a `RowOutcome`, optionally carrying a `RollbackRecord` that describes how to undo this row later:

```php
use System\Services\Imports\Contracts\RowProcessorInterface;
use System\Services\Imports\ImportContext;
use System\Services\Imports\Model\RollbackRecord;
use System\Services\Imports\Model\RowOutcome;
use App\Models\Customer;

final class CustomerRowProcessor implements RowProcessorInterface
{
    public function process(array $row, ImportContext $ctx): RowOutcome
    {
        $id = Customer::create([
            'email' => $row['email'],
            'name'  => $row['name'],
            'plan'  => $row['plan'] ?? 'free',
        ]);

        // Record how to undo this insert
        return RowOutcome::success(
            rollback: new RollbackRecord('customer.delete', ['id' => $id])
        );
    }
}
```

Use `RowOutcome::failure()` when the row is well-formed but couldn't be processed (e.g. a downstream API rejected it) — it counts toward `failedRows` without recording a rollback.

## Running an import

`ImportManager` ties the pieces together. It's constructed with an `ImportStoreInterface` and optional context extras that are passed to every processor and rollback handler:

```php
$manager = new ImportManager(
    store: new FileImportStore(storage_path('imports')),
    contextExtras: ['tenant_id' => 7],   // available as $ctx->extras['tenant_id']
);
```

### `create()`

Registers a source file and returns a fresh `ImportState` with a random 32-char hex `id` and status `created`:

```php
$state = $manager->create('/path/to/file.csv', meta: ['source' => 'admin-upload']);
$importId = $state->id;
```

### `runInline()`

Processes the whole file synchronously and returns the final `ImportState`:

```php
$state = $manager->runInline($definition, $importId);
```

Row by row it: skips the header (line 1), applies the `ColumnMap`, runs the validators (first failure records errors and skips the row), calls the processor, and stores any `RollbackRecord`. Progress is flushed to the store every 200 rows, and the final state is marked `completed`. `ImportState` tracks `totalRows`, `processedRows`, and `failedRows`.

> `runInline` blocks until the file is done. For large files, run it from a [queue](/features/queues) job so the HTTP request returns immediately, and poll the store for progress.

### `rollback()`

Undoes a completed import by replaying its rollback records **in reverse order** through the definition's `rollbackHandler`:

```php
$state = $manager->rollback($definition, $importId);   // status → 'rolled_back'
```

The handler implements `RollbackHandlerInterface` and interprets the `RollbackRecord`s your processor emitted:

```php
use System\Services\Imports\Contracts\RollbackHandlerInterface;
use System\Services\Imports\ImportContext;
use System\Services\Imports\Model\RollbackRecord;
use App\Models\Customer;

final class CustomerRollbackHandler implements RollbackHandlerInterface
{
    public function rollback(RollbackRecord $record, ImportContext $ctx): void
    {
        if ($record->type === 'customer.delete') {
            Customer::query()->where('id', $record->payload['id'])->delete();
        }
    }
}
```

Calling `rollback()` without a configured `rollbackHandler` throws a `RuntimeException`.

## Import state and errors

`ImportState` is the persisted record of an import's progress:

| Property | Meaning |
| --- | --- |
| `id` | 32-char hex identifier |
| `sourcePath` | Absolute path to the CSV |
| `status` | `created` → `running` → `completed`, or `rolled_back` |
| `totalRows` / `processedRows` / `failedRows` | Counters updated during the run |
| `meta` | Your arbitrary metadata from `create()` |
| `createdAtUnix` / `updatedAtUnix` | Timestamps |

Validation failures are stored as `ImportError`s (`rowNumber`, `field`, `message`, `meta`) — the manager fills in the real CSV `rowNumber` when it records them, so you can show the user exactly which line and field failed.

## The store

Persistence is abstracted behind `ImportStoreInterface` (`create`/`update`/`get`, `addError`, `addRollback`/`listRollbacks`). `FileImportStore` ships out of the box and keeps state under a directory you choose. Implement the interface yourself to back imports with a database table instead.

## Reading CSVs directly

`CsvReader` is the streaming reader the manager uses internally, and you can use it on its own — it's an `IteratorAggregate` yielding `lineNumber => rowArray`, so it never loads the whole file into memory:

```php
use System\Services\Imports\Csv\CsvReader;

foreach (new CsvReader('/path/to/file.csv') as $line => $row) {
    if ($line === 1) continue;   // header
    // $row is a list of strings
}
```

Constructor options: `delimiter` (default `,`), `enclosure` (`"`), `escape` (`\`), and `hasHeader` (`true`).

::: warning Gotchas
- **The first validator to fail stops the row.** Order matters, and later validators won't run — collect all errors within a single validator if you want them reported together.
- **Rollback needs cooperating processors.** `rollback()` only reverses rows whose processor returned a `RollbackRecord`; rows processed without one can't be undone.
- **`runInline` is synchronous.** Push it onto a [queue](/features/queues) for large files.
- **Header matching is case-insensitive by default** but *not* whitespace-trimmed — a header of `" Email "` (with spaces) won't match `Email`. Normalize your CSVs or set `caseInsensitive` deliberately.
- **`ImportError::rowNumber` you pass is overwritten** by the manager with the real CSV line number — pass `0` in validators.
:::

## Related

- [Reporting](/features/reporting) — the outbound counterpart (your data → CSV/JSON)
- [Queues](/features/queues) — run large imports off the request cycle
- [Validation](/essentials/validation) — reuse framework rules inside a row validator
- [Models](/database/models) — persist imported rows
