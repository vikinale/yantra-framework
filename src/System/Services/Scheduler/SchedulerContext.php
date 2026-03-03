<?php
declare(strict_types=1);

namespace System\Services\Scheduler;

final class SchedulerContext
{
    /** @param array<string,mixed> $extras */
    public function __construct(
        public readonly \DateTimeImmutable $nowUtc,
        public readonly array $extras = []
    ) {}
}
