<?php
declare(strict_types=1);

namespace System\Services\Queue\Retry;

use System\Services\Queue\ReservedJob;

final class ExponentialBackoff implements RetryPolicyInterface
{
    public function __construct(
        private int $baseDelaySeconds = 5,
        private int $maxDelaySeconds = 600,
        private float $jitter = 0.2
    ) {}

    public function delaySeconds(ReservedJob $job, \Throwable $e): int
    {
        $attempt = max(1, $job->attempts);
        $delay = min($this->maxDelaySeconds, $this->baseDelaySeconds * (2 ** ($attempt - 1)));
        $jr = (int)round($delay * $this->jitter);
        $rand = $jr > 0 ? random_int(-$jr, $jr) : 0;
        return max(0, $delay + $rand);
    }
}
