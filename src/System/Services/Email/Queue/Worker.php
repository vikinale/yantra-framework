<?php
declare(strict_types=1);

namespace System\Services\Email\Queue;

use System\Services\\Email\Mailer;

/**
 * Minimal queue worker:
 * - polls queue
 * - executes supported job types
 * - exponential backoff on transient failures (app can tune)
 *
 * The application is responsible for:
 * - starting/stopping this loop
 * - supervising the process (systemd/supervisor/k8s)
 * - logging and metrics
 */
final class Worker
{
    public function __construct(
        private Mailer $mailer,
        private FileQueue $queue,
        private int $sleepSeconds = 2
    ) {}

    public function runOnce(): void
    {
        $job = $this->queue->pop();
        if ($job === null) {
            sleep($this->sleepSeconds);
            return;
        }

        try {
            if ($job->type === 'email.send') {
                $payload = SendEmailPayload::fromArray($job->payload);
                $msg = $payload->toMessage();
                $this->mailer->send($msg);
                $this->queue->ack($job->id);
                return;
            }

            // Unknown job type
            $this->queue->fail($job->id, 'Unknown job type: ' . $job->type);
        } catch (\Throwable $e) {
            // Retry with backoff
            if ($job->attempts + 1 >= $job->maxAttempts) {
                $this->queue->fail($job->id, 'Max attempts reached: ' . $e->getMessage());
                return;
            }

            $backoff = min(300, (int)pow(2, max(0, $job->attempts))); // seconds
            $this->queue->ack($job->id); // remove processing file
            $job->attempts += 1;
            $job->availableAt = time() + $backoff;
            $this->queue->push($job);
        }
    }

    public function loop(int $maxSeconds = 0): void
    {
        $start = time();
        while (true) {
            $this->runOnce();
            if ($maxSeconds > 0 && (time() - $start) >= $maxSeconds) break;
        }
    }
}
