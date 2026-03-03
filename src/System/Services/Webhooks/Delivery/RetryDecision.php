<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Delivery;

final class RetryDecision
{
    public function __construct(
        public readonly bool $shouldRetry,
        public readonly int $delayMs,
        public readonly string $reason
    ) {}

    public static function no(string $reason = 'no_retry'): self
    {
        return new self(false, 0, $reason);
    }

    public static function yes(int $delayMs, string $reason = 'retry'): self
    {
        return new self(true, $delayMs, $reason);
    }
}
