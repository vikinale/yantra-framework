<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Delivery;

final class DeliveryOutcome
{
    public function __construct(
        public readonly DeliveryAttempt $attempt,
        public readonly DeliveryResult $result,
        public readonly RetryDecision $retry
    ) {}
}
