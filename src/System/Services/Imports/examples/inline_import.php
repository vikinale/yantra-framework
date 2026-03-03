<?php
declare(strict_types=1);

use System\Services\\Imports\ImportManager;
use System\Services\\Imports\ImportDefinition;
use System\Services\\Imports\Mapping\ColumnMap;
use System\Services\\Imports\Stores\FileImportStore;

$store = new FileImportStore(__DIR__ . '/_imports');
$mgr = new ImportManager($store);

$state = $mgr->create(__DIR__ . '/sample.csv', meta: ['type' => 'contacts']);

$def = new ImportDefinition(
    name: 'Contacts CSV',
    mapping: new ColumnMap(['name' => 'Name', 'email' => 'Email']),
    validators: [new App\Imports\Validators\ContactValidator()],
    processor: new App\Imports\Processors\CreateContactProcessor(),
    rollbackHandler: new App\Imports\Rollback\ContactRollbackHandler(),
    chunkSize: 500
);

$mgr->runInline($def, $state->id);
