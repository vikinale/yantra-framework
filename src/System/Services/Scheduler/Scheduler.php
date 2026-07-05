<?php
declare(strict_types=1);

namespace System\Services\Scheduler;

use System\Services\Scheduler\Contracts\ScheduleStoreInterface;
use System\Services\Scheduler\Contracts\ObserverInterface;

final class Scheduler
{
    /** @var ObserverInterface[] */
    private array $observers = [];

    /** @param array<string,mixed> $contextExtras */
    public function __construct(private ScheduleRegistry $registry, private ?ScheduleStoreInterface $store = null, private array $contextExtras = []) {}

    public function addObserver(ObserverInterface $observer): void { $this->observers[] = $observer; }

    /** @return ScheduleRun[] */
    public function runDue(?\DateTimeImmutable $nowUtc = null): array
    {
        $nowUtc = $nowUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ctx = new SchedulerContext($nowUtc, $this->contextExtras);

        $schedules = $this->registry->all();
        if ($this->store) {
            foreach ($this->store->loadSchedules() as $s) $schedules[] = $s;
        }

        $runs=[];
        foreach ($schedules as $s) {
            if (!$s->isDue($nowUtc)) continue;
            foreach ($this->observers as $o) $o->onDue($s);

            $started = time();
            try {
                $s->task->run($ctx);
                $run = new ScheduleRun($s->id, $s->name, $started, time(), 'success', meta: $s->meta);
                $runs[] = $run;
                if ($this->store) $this->store->recordRun($run);
                foreach ($this->observers as $o) $o->onRunCompleted($run);
            } catch (\Throwable $e) {
                $run = new ScheduleRun($s->id, $s->name, $started, time(), 'failed', get_class($e), (string)$e->getMessage(), meta: $s->meta);
                $runs[] = $run;
                if ($this->store) $this->store->recordRun($run);
                foreach ($this->observers as $o) $o->onRunFailed($run, $e);
            }
        }
        return $runs;
    }
}
