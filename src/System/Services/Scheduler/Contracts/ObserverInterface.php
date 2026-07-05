<?php
declare(strict_types=1);

namespace System\Services\Scheduler\Contracts;

use System\Services\Scheduler\Schedule;
use System\Services\Scheduler\ScheduleRun;

interface ObserverInterface
{
    public function onDue(Schedule $schedule): void;
    public function onRunCompleted(ScheduleRun $run): void;
    public function onRunFailed(ScheduleRun $run, \Throwable $e): void;
}
