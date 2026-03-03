<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Delivery;

final class DispatchResults
{
    /**
     * @param DeliveryOutcome[] $deliveries
     */
    public function __construct(
        public readonly array $deliveries
    ) {}
}
