<?php
declare(strict_types=1);

namespace System\Services\Scheduler\Contracts;

use System\Services\\Scheduler\Schedule;
use System\Services\\Scheduler\ScheduleRun;

interface ScheduleStoreInterface
{
    public function saveSchedule(Schedule $schedule): void;
    /** @return Schedule[] */
    public function loadSchedules(): array;
    public function recordRun(ScheduleRun $run): void;
}
