<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Services\\Queue\Adapters\FileQueue;
use System\Services\\Queue\Worker;
use System\Services\\Queue\Serializers\JsonSerializer;
use System\Services\\Queue\Retry\ExponentialBackoff;

final class QueueWorkCommand extends AbstractCommand
{
    public function name(): string { return 'queue:work'; }
    public function description(): string { return 'Run the queue worker loop (FileQueue default).'; }

    public function usage(): array
    {
        return [
            'yantra queue:work',
            'yantra queue:work --sleep_ms=250 --wait=3 --vt=60',
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $sleepMs = (int)($this->getOpt($in, 'sleep_ms') ?? '250');
        $wait = (int)($this->getOpt($in, 'wait') ?? '3');
        $vt = (int)($this->getOpt($in, 'vt') ?? '60');

        $base = function_exists('storage_path') ? storage_path('queue') : (BASEPATH . '/storage/queue');
        $queue = new FileQueue($base);

        $worker = new Worker($queue, new JsonSerializer(), new ExponentialBackoff());

        // TODO: Register your handlers
        // $worker->registerHandler(App\Jobs\MyJob::class, new App\Jobs\MyJob());

        $out->writeln('Queue worker started. Ctrl+C to stop.');
        $worker->work($sleepMs, $wait, $vt);
        return 0;
    }
}
