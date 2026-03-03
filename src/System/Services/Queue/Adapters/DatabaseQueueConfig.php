<?php
declare(strict_types=1);

namespace System\Services\Queue\Adapters;

final class DatabaseQueueConfig
{
    public function __construct(
        public readonly string $jobsTable = 'jobs',
        public readonly string $failedJobsTable = 'failed_jobs',
        public readonly string $queue = 'default'
    ) {}
}
