<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Delivery;

use System\Services\\Webhooks\Value\WebhookEndpoint;

final class DeliveryRequest
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly WebhookEndpoint $endpoint,
        public readonly string $body,
        public readonly array $headers,
        public readonly int $timeoutSeconds,
        public readonly int $connectTimeoutSeconds
    ) {}
}
