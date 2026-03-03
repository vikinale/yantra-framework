<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Contracts;

use System\Services\\Webhooks\Events\WebhookEvent;
use System\Services\\Webhooks\Value\WebhookEndpoint;

/**
 * Application-owned: resolve endpoints that should receive a given event.
 *
 * Endpoints might come from DB, config, or an external system.
 */
interface EndpointResolverInterface
{
    /**
     * @return WebhookEndpoint[] ordered list of endpoints to attempt
     */
    public function resolveEndpoints(WebhookEvent $event): array;
}
