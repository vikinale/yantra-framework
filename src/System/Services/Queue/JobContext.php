<?php
declare(strict_types=1);

namespace System\Services\Queue;

final class JobContext
{
    /** @param array<string,mixed> $extras */
    public function __construct(
        public readonly string $jobId,
        public readonly string $queue,
        public readonly int $attempt,
        public readonly int $maxAttempts,
        public readonly int $timeoutSeconds,
        public readonly array $extras = []
    ) {}
}
