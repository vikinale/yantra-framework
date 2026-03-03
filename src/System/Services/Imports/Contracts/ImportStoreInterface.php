<?php
declare(strict_types=1);

namespace System\Services\Imports\Contracts;

use System\Services\\Imports\Model\ImportState;
use System\Services\\Imports\Model\ImportError;
use System\Services\\Imports\Model\RollbackRecord;

interface ImportStoreInterface
{
    public function create(ImportState $state): void;
    public function update(ImportState $state): void;
    public function get(string $importId): ?ImportState;

    public function addError(string $importId, ImportError $error): void;

    public function addRollback(string $importId, RollbackRecord $record): void;

    /** @return RollbackRecord[] */
    public function listRollbacks(string $importId): array;
}
