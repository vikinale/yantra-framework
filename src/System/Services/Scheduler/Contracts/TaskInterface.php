<?php
declare(strict_types=1);

namespace System\Services\Scheduler\Contracts;

use System\Services\\Scheduler\SchedulerContext;

interface TaskInterface
{
    public function name(): string;
    public function run(SchedulerContext $ctx): void;
}
