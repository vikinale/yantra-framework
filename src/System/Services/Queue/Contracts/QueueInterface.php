<?php
declare(strict_types=1);

namespace System\Services\Queue\Contracts;

use System\Services\Queue\JobPayload;
use System\Services\Queue\ReservedJob;
use System\Services\Queue\FailureInfo;

interface QueueInterface
{
    public function push(JobPayload $job, int $delaySeconds = 0): string;

    public function reserve(int $waitSeconds = 3, int $visibilityTimeoutSeconds = 60): ?ReservedJob;

    public function ack(string $jobId): void;

    public function release(string $jobId, int $delaySeconds = 0): void;

    public function fail(string $jobId, FailureInfo $info): void;
}
