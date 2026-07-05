<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Contracts;

use System\Services\Webhooks\Delivery\DeliveryRequest;
use System\Services\Webhooks\Delivery\DeliveryResult;

interface TransportInterface
{
    public function send(DeliveryRequest $request): DeliveryResult;
}
