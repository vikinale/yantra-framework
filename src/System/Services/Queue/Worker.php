<?php
declare(strict_types=1);

namespace System\Services\Queue;

use System\Services\Queue\Contracts\QueueInterface;
use System\Services\Queue\Contracts\SerializerInterface;
use System\Services\Queue\Contracts\JobHandlerInterface;
use System\Services\Queue\Exceptions\HandlerNotRegisteredException;
use System\Services\Queue\Retry\RetryPolicyInterface;

final class Worker
{
    /** @var array<string, JobHandlerInterface> */
    private array $handlers = [];

    /** @param array<string,mixed> $contextExtras */
    public function __construct(
        private QueueInterface $queue,
        private SerializerInterface $serializer,
        private RetryPolicyInterface $retryPolicy,
        private array $contextExtras = []
    ) {}

    public function registerHandler(string $handlerFqcn, JobHandlerInterface $handler): void
    {
        $this->handlers[$handlerFqcn] = $handler;
    }

    public function work(int $sleepMs = 250, int $waitSeconds = 3, int $visibilityTimeoutSeconds = 60): void
    {
        while (true) {
            $did = $this->runOnce($waitSeconds, $visibilityTimeoutSeconds);
            if (!$did) usleep(max(0,$sleepMs) * 1000);
        }
    }

    public function runOnce(int $waitSeconds = 3, int $visibilityTimeoutSeconds = 60): bool
    {
        $job = $this->queue->reserve($waitSeconds, $visibilityTimeoutSeconds);
        if ($job === null) return false;

        $start = microtime(true);
        try {
            $decoded = $job->payloadDecoded;
            $handler = $this->handlers[$job->handler] ?? null;
            if (!$handler) {
                throw new HandlerNotRegisteredException("No handler registered for {$job->handler}");
            }

            $maxAttempts = (int)($decoded['maxAttempts'] ?? 5);
            $timeout = (int)($decoded['timeoutSeconds'] ?? 60);
            $data = $decoded['data'] ?? [];
            if (!is_array($data)) $data = [];

            $ctx = new JobContext(
                jobId: $job->id,
                queue: $job->queue,
                attempt: $job->attempts,
                maxAttempts: $maxAttempts,
                timeoutSeconds: $timeout,
                extras: $this->contextExtras
            );

            $handler->handle($data, $ctx);
            $this->queue->ack($job->id);
            return true;

        } catch (\Throwable $e) {
            $decoded = $job->payloadDecoded;
            $maxAttempts = (int)($decoded['maxAttempts'] ?? 5);

            if ($job->attempts < $maxAttempts) {
                $delay = $this->retryPolicy->delaySeconds($job, $e);
                $this->queue->release($job->id, $delay);
            } else {
                $this->queue->fail($job->id, FailureInfo::fromThrowable($e));
            }
            return true;
        } finally {
            $durMs = (microtime(true) - $start) * 1000;
        }
    }
}
