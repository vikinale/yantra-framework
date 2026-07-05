<?php
declare(strict_types=1);

namespace System\Services\Imports;

use System\Services\Imports\Mapping\ColumnMap;
use System\Services\Imports\Contracts\RowValidatorInterface;
use System\Services\Imports\Contracts\RowProcessorInterface;
use System\Services\Imports\Contracts\RollbackHandlerInterface;

final class ImportDefinition
{
    /** @param RowValidatorInterface[] $validators */
    public function __construct(
        public readonly string $name,
        public readonly ColumnMap $mapping,
        public readonly array $validators,
        public readonly RowProcessorInterface $processor,
        public readonly ?RollbackHandlerInterface $rollbackHandler = null,
        public readonly int $chunkSize = 500
    ) {}
}
