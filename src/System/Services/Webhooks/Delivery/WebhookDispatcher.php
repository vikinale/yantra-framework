<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Delivery;

use System\Services\\Webhooks\Contracts\DeliveryObserverInterface;
use System\Services\\Webhooks\Contracts\EndpointResolverInterface;
use System\Services\\Webhooks\Contracts\TransportInterface;
use System\Services\\Webhooks\Events\WebhookEvent;
use System\Services\\Webhooks\Security\SignatureConfig;

final class WebhookDispatcher
{
    private WebhookClient $client;

    public function __construct(
        private readonly EndpointResolverInterface $endpointResolver,
        private readonly ?DeliveryObserverInterface $observer = null,
        ?SignatureConfig $signatureConfig = null,
        ?RetryPolicy $retryPolicy = null,
        ?TransportInterface $transport = null
    ) {
        $signatureConfig = $signatureConfig ?? SignatureConfig::defaults();
        $retryPolicy = $retryPolicy ?? RetryPolicy::exponentialBackoff();
        $transport = $transport ?? new CurlTransport();

        $this->signatureConfig = $signatureConfig;
        $this->retryPolicy = $retryPolicy;
        $this->client = new WebhookClient($transport, $signatureConfig);
    }

    private SignatureConfig $signatureConfig;
    private RetryPolicy $retryPolicy;

    /**
     * Dispatch the event to all resolved endpoints.
     *
     * @param int $attempt attempt number (1..N) used for retryPolicy decisions
     * @return DispatchResults
     */
    public function dispatch(WebhookEvent $event, int $attempt = 1, ?int $queuedAt = null): DispatchResults
    {
        $queuedAt = $queuedAt ?? time();
        $outcomes = [];

        $endpoints = $this->endpointResolver->resolveEndpoints($event);

        foreach ($endpoints as $endpoint) {
            if (!$endpoint->accepts($event->type)) {
                continue;
            }

            // Prepare deterministically so observer sees exact headers/body used.
            $prepared = $this->client->prepare($endpoint, $event);

            $attemptObj = new DeliveryAttempt(
                event: $event,
                endpoint: $endpoint,
                attempt: $attempt,
                queuedAt: $queuedAt,
                headers: $prepared['headers'],
                body: $prepared['body']
            );

            $this->observer?->onAttempt($attemptObj);

            $result = $this->client->sendPrepared($prepared['request']);
            $retry = $this->retryPolicy->decide($attempt, $result);

            $outcomes[] = new DeliveryOutcome($attemptObj, $result, $retry);

            $this->observer?->onResult($attemptObj, $result);
        }

        return new DispatchResults($outcomes);
    }
}
