<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Value;

/**
 * Application-owned endpoint configuration object.
 * This is intentionally immutable.
 */
final class WebhookEndpoint
{
    /**
     * @param array<string,string> $headers extra headers to send
     * @param string[] $eventTypes optional allowlist; empty = all events
     */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly string $secret,
        public readonly bool $enabled = true,
        public readonly array $headers = [],
        public readonly array $eventTypes = [],
        public readonly int $timeoutSeconds = 10,
        public readonly int $connectTimeoutSeconds = 5
    ) {}

    public function accepts(string $eventType): bool
    {
        if (!$this->enabled) return false;
        if ($this->eventTypes === []) return true;
        return in_array($eventType, $this->eventTypes, true);
    }
}
