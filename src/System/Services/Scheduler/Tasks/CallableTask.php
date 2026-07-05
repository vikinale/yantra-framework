<?php
declare(strict_types=1);

namespace System\Services\Scheduler\Tasks;

use System\Services\Scheduler\Contracts\TaskInterface;
use System\Services\Scheduler\SchedulerContext;

final class CallableTask implements TaskInterface
{
    public function __construct(private string $name, private $fn) {}

    public function name(): string { return $this->name; }

    public function run(SchedulerContext $ctx): void
    {
        ($this->fn)($ctx);
    }
}
