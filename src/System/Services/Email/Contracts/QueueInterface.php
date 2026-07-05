<?php
declare(strict_types=1);

namespace System\Services\Email\Contracts;

use System\Services\Email\Queue\QueueJob;

interface QueueInterface
{
    /** Enqueue a job and return a job id. */
    public function push(QueueJob $job): string;

    /**
     * Pop the next available job for processing.
     * Implementations should support delayed jobs.
     */
    public function pop(): ?QueueJob;

    /** Acknowledge successful processing. */
    public function ack(string $jobId): void;

    /** Mark job as failed (may be moved to failed store). */
    public function fail(string $jobId, string $reason): void;

    public function size(): int;
}
