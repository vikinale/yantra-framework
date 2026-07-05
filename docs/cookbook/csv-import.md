# Import a CSV with Validation and Rollback

This recipe imports a customers CSV end to end: map columns, validate every row, insert the good ones, and — if you need to — reverse the whole import. It uses the [Imports subsystem](/features/imports) (`System\Services\Imports`): you describe the import once as an `ImportDefinition`, drive it through the `ImportManager`, and rely on per-row `RollbackRecord`s to undo it later.

```php
use System\Services\Imports\ImportManager;
use System\Services\Imports\Stores\FileImportStore;

$manager = new ImportManager(new FileImportStore(storage_path('imports')));

$state = $manager->create(storage_path('uploads/customers.csv'));   // → import id
$state = $manager->runInline($definition, $state->id);              // process synchronously

echo "{$state->processedRows} imported, {$state->failedRows} failed";

// Later, if something went wrong:
$manager->rollback($definition, $state->id);                        // status → 'rolled_back'
```

## The CSV

Assume `customers.csv` with a header row:

```csv
Email Address,Full Name,Plan
alice@example.com,Alice Adams,pro
bob@example.com,Bob Brown,free
,Carol Clark,pro
```

The third row has no email — the validator will reject it, and the import continues with the rest.

## Step 1 — Map columns to fields

`ColumnMap` maps your internal field names to CSV column headers (case-insensitive by default):

```php
use System\Services\Imports\Mapping\ColumnMap;

$mapping = new ColumnMap([
    'email' => 'Email Address',   // field => CSV column header
    'name'  => 'Full Name',
    'plan'  => 'Plan',
]);
```

A field whose column is missing from the header resolves to `null`, so your validator can catch it.

## Step 2 — Validate each row

A validator implements `RowValidatorInterface` and receives the **mapped** row. Return `RowValidationResult::ok()` to pass, or `RowValidationResult::fail([...])` with one or more `ImportError`s. Pass `0` for the row number — the manager overwrites it with the real CSV line:

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
            $errors[] = new ImportError(0, 'email', 'Invalid email address');
        }
        if (empty($row['name'])) {
            $errors[] = new ImportError(0, 'name', 'Name is required');
        }

        return $errors === [] ? RowValidationResult::ok() : RowValidationResult::fail($errors);
    }
}
```

The first failing validator stops that row, so collect all errors *within* a single validator if you want them reported together. You can also reuse the framework [Validator](/essentials/validation) inside this method if you want its rule set.

## Step 3 — Process a valid row and record how to undo it

The processor implements `RowProcessorInterface`. It does the real work — here, inserting a `Customer` — and returns a `RowOutcome`. Attach a `RollbackRecord` so this row can be reversed later:

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
        $id = Customer::create([         // create() returns the new row's ID
            'email' => $row['email'],
            'name'  => $row['name'],
            'plan'  => $row['plan'] ?? 'free',
        ]);

        return RowOutcome::success(
            rollback: new RollbackRecord('customer.delete', ['id' => $id])
        );
    }
}
```

Use `RowOutcome::failure()` when a well-formed row can't be processed (e.g. a downstream API rejected it) — it counts as a failure without recording a rollback.

## Step 4 — Define how to undo a row

The rollback handler implements `RollbackHandlerInterface` and interprets each `RollbackRecord` your processor emitted:

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

## Step 5 — Assemble the definition and run

Wire the four pieces into an immutable `ImportDefinition`, then drive it with the manager:

```php
use System\Services\Imports\ImportDefinition;
use System\Services\Imports\ImportManager;
use System\Services\Imports\Stores\FileImportStore;

$definition = new ImportDefinition(
    name: 'customers',
    mapping: new ColumnMap([
        'email' => 'Email Address',
        'name'  => 'Full Name',
        'plan'  => 'Plan',
    ]),
    validators: [new CustomerRowValidator()],       // RowValidatorInterface[]
    processor: new CustomerRowProcessor(),            // RowProcessorInterface
    rollbackHandler: new CustomerRollbackHandler(),   // RollbackHandlerInterface
);

$manager = new ImportManager(new FileImportStore(storage_path('imports')));

// 1. Register the file → fresh ImportState with a 32-char hex id
$state = $manager->create(storage_path('uploads/customers.csv'), meta: ['uploaded_by' => 42]);

// 2. Process the whole file synchronously
$state = $manager->runInline($definition, $state->id);

printf(
    "%d/%d imported, %d failed\n",
    $state->processedRows,
    $state->totalRows,
    $state->failedRows
);
```

`runInline()` skips the header, applies the `ColumnMap`, runs the validators (first failure records errors and skips the row), calls the processor, and stores any `RollbackRecord`. The final `ImportState` is marked `completed` and carries `totalRows`, `processedRows`, and `failedRows`.

## Step 6 — Roll the import back

Undo a completed import by replaying its rollback records **in reverse order** through the handler:

```php
$state = $manager->rollback($definition, $state->id);   // status → 'rolled_back'
```

Only rows whose processor returned a `RollbackRecord` are reversed — rows processed without one can't be undone.

::: warning Gotchas
- **`runInline` is synchronous** and blocks until the file is done. For large uploads, run it from a [queue](/features/queues) job so the request returns immediately, and poll the store for progress.
- **The first validator to fail stops the row** — later validators won't run. Collect all errors inside one validator if you want them reported together.
- **Rollback needs cooperating processors.** `rollback()` only reverses rows that emitted a `RollbackRecord`; calling it without a configured `rollbackHandler` throws a `RuntimeException`.
- **Header matching is case-insensitive but not whitespace-trimmed** — a header of `" Email "` (with spaces) won't match `Email`. Normalize your CSVs.
- **The `rowNumber` you pass to `ImportError` is overwritten** by the manager with the real CSV line — always pass `0` in validators.
:::

## Related

- [Imports](/features/imports) — the full subsystem reference
- [Validation](/essentials/validation) — reuse framework rules inside a row validator
- [Custom Validation Rule cookbook](/cookbook/custom-validation-rule) — reusable rule objects you can call from a validator
- [Models](/database/models) — persist imported rows
- [Custom Command cookbook](/cookbook/custom-command) — run an import from the CLI
