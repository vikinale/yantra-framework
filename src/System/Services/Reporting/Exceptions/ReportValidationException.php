<?php
declare(strict_types=1);

namespace System\Services\Reporting\Exceptions;

use RuntimeException;

final class ReportValidationException extends RuntimeException
{
    /** @var array<string, list<string>> */
    private array $errors;

    /** @var array<string, mixed> */
    private array $input;

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed> $input
     */
    public function __construct(array $errors, array $input = [])
    {
        $this->errors = $errors;
        $this->input = $input;
        parent::__construct('Report validation failed');
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function input(): array
    {
        return $this->input;
    }
}
