<?php
declare(strict_types=1);

namespace System\Services\Imports\Contracts;

use System\Services\\Imports\Model\RollbackRecord;
use System\Services\\Imports\ImportContext;

interface RollbackHandlerInterface
{
    public function rollback(RollbackRecord $record, ImportContext $ctx): void;
}
