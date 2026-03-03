<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Services\\Scheduler\ScheduleRegistry;
use System\Services\\Scheduler\Scheduler;

final class ScheduleRunCommand extends AbstractCommand
{
    public function name(): string { return 'schedule:run'; }
    public function description(): string { return 'Run due scheduled tasks for current minute.'; }

    public function usage(): array { return ['yantra schedule:run']; }

    public function run(Input $in, Output $out): int
    {
        $registry = new ScheduleRegistry();

        // TODO: register schedules in app code
        // $registry->addCallable('cleanup.tmp','Cleanup tmp','*/5 * * * *', function($ctx) {...});

        $scheduler = new Scheduler($registry);
        $runs = $scheduler->runDue();

        $out->writeln('Ran '.count($runs).' schedule(s).');
        foreach ($runs as $r) $out->writeln('- '.$r->scheduleName.' => '.$r->status);
        return 0;
    }
}
