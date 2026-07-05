<?php
declare(strict_types=1);

namespace System\Services\Imports\Contracts;

use System\Services\Imports\ImportContext;
use System\Services\Imports\Model\RowOutcome;

interface RowProcessorInterface
{
    /** @param array<string,mixed> $row */
    public function process(array $row, ImportContext $ctx): RowOutcome;
}
