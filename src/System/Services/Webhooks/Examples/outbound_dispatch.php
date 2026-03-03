<?php
declare(strict_types=1);

use System\Services\\Webhooks\Contracts\EndpointResolverInterface;
use System\Services\\Webhooks\Contracts\DeliveryObserverInterface;
use System\Services\\Webhooks\Delivery\WebhookDispatcher;
use System\Services\\Webhooks\Delivery\RetryPolicy;
use System\Services\\Webhooks\Events\WebhookEvent;
use System\Services\\Webhooks\Value\WebhookEndpoint;

final class ConfigEndpointResolver implements EndpointResolverInterface
{
    public function __construct(private array $endpoints) {}

    public function resolveEndpoints(WebhookEvent $event): array
    {
        return $this->endpoints;
    }
}

final class NullObserver implements DeliveryObserverInterface
{
    public function onAttempt(\Services\Webhooks\Delivery\DeliveryAttempt $attempt): void {}
    public function onResult(\Services\Webhooks\Delivery\DeliveryAttempt $attempt, \Services\Webhooks\Delivery\DeliveryResult $result): void {}
}

$endpoints = [
  new WebhookEndpoint(id: 'p1', url: 'https://example.com/webhooks', secret: 'secret123', eventTypes: ['invoice.paid']),
];

$dispatcher = new WebhookDispatcher(
  endpointResolver: new ConfigEndpointResolver($endpoints),
  observer: new NullObserver(),
  retryPolicy: RetryPolicy::exponentialBackoff()
);

$event = WebhookEvent::create('invoice.paid', ['invoice_id' => 1, 'amount' => 999]);
$results = $dispatcher->dispatch($event);

foreach ($results->deliveries as $d) {
  echo $d->attempt->endpoint->id . ' status=' . $d->result->statusCode . ' retry=' . ($d->retry->shouldRetry ? 'yes' : 'no') . PHP_EOL;
}
