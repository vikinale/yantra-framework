<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Delivery;

use System\Services\\Webhooks\Events\WebhookEvent;
use System\Services\\Webhooks\Value\WebhookEndpoint;

final class DeliveryAttempt
{
    public function __construct(
        public readonly WebhookEvent $event,
        public readonly WebhookEndpoint $endpoint,
        public readonly int $attempt,      // 1..N
        public readonly int $queuedAt,     // unix seconds
        public readonly array $headers,    // array<string,string>
        public readonly string $body
    ) {}
}
