<?php
declare(strict_types=1);

namespace System\Testing\Sandbox;

use DateTimeImmutable;

class ClockFake
{
    private ?DateTimeImmutable $frozenAt = null;

    /**
     * Freeze time to a specific point.
     * 
     * @param string $datetimeString DateTime string (e.g. '2023-01-01 12:00:00')
     */
    public function freeze(string $datetimeString): void
    {
        $this->frozenAt = new DateTimeImmutable($datetimeString);
    }

    /**
     * Reset time to current system time.
     */
    public function reset(): void
    {
        $this->frozenAt = null;
    }

    /**
     * Get the current time (frozen or real).
     */
    public function now(): DateTimeImmutable
    {
        return $this->frozenAt ?? new DateTimeImmutable();
    }
    
    /**
     * Get current timestamp.
     */
    public function timestamp(): int
    {
        return $this->now()->getTimestamp();
    }
}
