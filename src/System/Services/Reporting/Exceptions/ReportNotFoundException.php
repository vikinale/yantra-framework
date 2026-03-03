<?php
declare(strict_types=1);

namespace System\Services\Reporting\Exceptions;

use RuntimeException;

final class ReportNotFoundException extends RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("Report not found: {$key}");
    }
}
