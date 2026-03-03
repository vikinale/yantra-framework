<?php
declare(strict_types=1);

namespace System\Services\Reporting\Exceptions;

use RuntimeException;
use Throwable;

final class ReportExecutionException extends RuntimeException
{
    private string $reportKey;

    public function __construct(string $reportKey, Throwable $previous)
    {
        $this->reportKey = $reportKey;
        parent::__construct("Report execution failed: {$reportKey}", 0, $previous);
    }

    public function reportKey(): string
    {
        return $this->reportKey;
    }
}
