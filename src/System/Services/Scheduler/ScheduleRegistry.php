<?php
declare(strict_types=1);

namespace System\Services\Scheduler;

use System\Services\Scheduler\Tasks\CallableTask;

final class ScheduleRegistry
{
    /** @var Schedule[] */
    private array $schedules = [];

    public function add(Schedule $schedule): void { $this->schedules[] = $schedule; }

    public function addCallable(string $id, string $name, string $cron, callable $fn, string $timezone='UTC', bool $enabled=true, array $meta=[]): void
    {
        $this->add(new Schedule($id, $name, $cron, new CallableTask($name, $fn), $timezone, $enabled, $meta));
    }

    /** @return Schedule[] */
    public function all(): array { return $this->schedules; }
}
