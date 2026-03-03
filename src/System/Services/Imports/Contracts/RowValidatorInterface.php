<?php
declare(strict_types=1);

namespace System\Services\Imports\Contracts;

use System\Services\\Imports\Model\RowValidationResult;

interface RowValidatorInterface
{
    /** @param array<string,mixed> $row */
    public function validate(array $row): RowValidationResult;
}
