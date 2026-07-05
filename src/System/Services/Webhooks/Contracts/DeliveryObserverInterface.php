<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Contracts;

use System\Services\Webhooks\Delivery\DeliveryAttempt;
use System\Services\Webhooks\Delivery\DeliveryResult;

/**
 * Application-owned: observe delivery lifecycle for logging/metrics/auditing.
 *
 * Implementations may persist delivery attempts and results in the application's DB.
 */
interface DeliveryObserverInterface
{
    public function onAttempt(DeliveryAttempt $attempt): void;

    public function onResult(DeliveryAttempt $attempt, DeliveryResult $result): void;
}
