<?php
declare(strict_types=1);

namespace System\Services\Scheduler;

final class ScheduleRun
{
    public function __construct(
        public readonly string $scheduleId,
        public readonly string $scheduleName,
        public readonly int $startedAtUnix,
        public readonly ?int $finishedAtUnix,
        public readonly string $status,
        public readonly ?string $errorClass = null,
        public readonly ?string $errorMessage = null,
        public readonly array $meta = []
    ) {}
}
