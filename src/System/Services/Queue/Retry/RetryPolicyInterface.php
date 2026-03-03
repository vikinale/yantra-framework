<?php
declare(strict_types=1);

namespace System\Services\Queue\Retry;

use System\Services\\Queue\ReservedJob;

interface RetryPolicyInterface
{
    public function delaySeconds(ReservedJob $job, \Throwable $e): int;
}
