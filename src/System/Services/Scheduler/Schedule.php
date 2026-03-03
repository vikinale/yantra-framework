<?php
declare(strict_types=1);

namespace System\Services\Scheduler;

use System\Services\\Scheduler\Contracts\TaskInterface;
use System\Services\\Scheduler\Cron\CronExpression;

final class Schedule
{
    private CronExpression $cronObj;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $cron,
        public readonly TaskInterface $task,
        public readonly string $timezone = 'UTC',
        public readonly bool $enabled = true,
        public readonly array $meta = []
    ) {
        $this->cronObj = CronExpression::parse($cron);
    }

    public function isDue(\DateTimeImmutable $nowUtc): bool
    {
        if (!$this->enabled) return false;
        $dt = $nowUtc->setTimezone(new \DateTimeZone($this->timezone));
        return $this->cronObj->isDue($dt);
    }
}
