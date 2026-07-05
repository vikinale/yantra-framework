<?php
declare(strict_types=1);

namespace System\Services\Scheduler\Stores;

use System\Services\Scheduler\Contracts\ScheduleStoreInterface;
use System\Services\Scheduler\Schedule;
use System\Services\Scheduler\ScheduleRun;

final class FileRunStore implements ScheduleStoreInterface
{
    private string $runsFile;

    public function __construct(private string $baseDir)
    {
        $d = rtrim($baseDir, '/\\');
        if (!is_dir($d)) mkdir($d, 0775, true);
        $this->runsFile = $d . DIRECTORY_SEPARATOR . 'schedule_runs.jsonl';
    }

    public function saveSchedule(Schedule $schedule): void {}
    public function loadSchedules(): array { return []; }

    public function recordRun(ScheduleRun $run): void
    {
        $line = json_encode([
            'scheduleId'=>$run->scheduleId,'scheduleName'=>$run->scheduleName,
            'startedAt'=>$run->startedAtUnix,'finishedAt'=>$run->finishedAtUnix,
            'status'=>$run->status,'errorClass'=>$run->errorClass,'errorMessage'=>$run->errorMessage,
            'meta'=>$run->meta,
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        file_put_contents($this->runsFile, $line."\n", FILE_APPEND | LOCK_EX);
    }
}
