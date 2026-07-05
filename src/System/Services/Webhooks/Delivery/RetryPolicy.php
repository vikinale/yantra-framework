<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Delivery;

final class RetryPolicy
{
    private function __construct(
        public readonly int $maxAttempts,
        public readonly int $baseDelayMs,
        public readonly int $maxDelayMs,
        public readonly float $jitterRatio
    ) {}

    public static function exponentialBackoff(
        int $maxAttempts = 6,
        int $baseDelayMs = 500,
        int $maxDelayMs = 60_000,
        float $jitterRatio = 0.2
    ): self {
        return new self($maxAttempts, $baseDelayMs, $maxDelayMs, $jitterRatio);
    }

    public function decide(int $attempt, DeliveryResult $result): RetryDecision
    {
        // attempt starts at 1
        if ($attempt >= $this->maxAttempts) {
            return RetryDecision::no('max_attempts_reached');
        }

        if ($result->ok) {
            return RetryDecision::no('delivered');
        }

        // Locally blocked (e.g. SSRF-unsafe URL) is permanent; never retry.
        if ($result->blocked) {
            return RetryDecision::no($result->error ?? 'blocked');
        }

        // Retry for network errors
        if ($result->statusCode === 0) {
            return RetryDecision::yes($this->delayMs($attempt), 'network_error');
        }

        // Retry for 429 and 5xx
        if ($result->statusCode === 429 || ($result->statusCode >= 500 && $result->statusCode <= 599)) {
            return RetryDecision::yes($this->delayMs($attempt), 'http_' . $result->statusCode);
        }

        // Do not retry for 4xx (except 429)
        return RetryDecision::no('http_' . $result->statusCode);
    }

    private function delayMs(int $attempt): int
    {
        // Exponential: base * 2^(attempt-1)
        $delay = (int)min($this->maxDelayMs, $this->baseDelayMs * (2 ** max(0, $attempt - 1)));

        // Jitter
        $jitter = (int)round($delay * $this->jitterRatio);
        if ($jitter > 0) {
            $delay += random_int(-$jitter, $jitter);
        }
        return max(0, $delay);
    }
}
