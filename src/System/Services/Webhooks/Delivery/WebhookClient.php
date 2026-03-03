<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Delivery;

use System\Services\\Webhooks\Contracts\TransportInterface;
use System\Services\\Webhooks\Events\EventSerializer;
use System\Services\\Webhooks\Events\WebhookEvent;
use System\Services\\Webhooks\Security\SignatureConfig;
use System\Services\\Webhooks\Security\Signer;
use System\Services\\Webhooks\Value\WebhookEndpoint;

/**
 * Builds and sends outbound webhook HTTP requests.
 *
 * - Serializes the event as JSON
 * - Adds signature/timestamp/event-id headers
 * - Sends via an injected Transport
 */
final class WebhookClient
{
    private EventSerializer $serializer;
    private Signer $signer;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly SignatureConfig $signatureConfig
    ) {
        $this->serializer = new EventSerializer();
        $this->signer = new Signer();
    }

    /**
     * Prepare the request (body + headers) deterministically.
     *
     * @return array{request:DeliveryRequest, body:string, headers:array<string,string>, timestamp:int, signature:string}
     */
    public function prepare(WebhookEndpoint $endpoint, WebhookEvent $event, ?int $timestamp = null): array
    {
        $body = $this->serializer->toJson($event);

        $timestamp = $timestamp ?? time();
        $signature = $this->signer->sign($endpoint->secret, $timestamp, $body);

        $headers = array_merge([
            'Content-Type' => 'application/json',
            $this->signatureConfig->signatureHeader => $signature,
            $this->signatureConfig->timestampHeader => (string)$timestamp,
            $this->signatureConfig->eventIdHeader => $event->id,
        ], $endpoint->headers);

        $req = new DeliveryRequest(
            endpoint: $endpoint,
            body: $body,
            headers: $headers,
            timeoutSeconds: $endpoint->timeoutSeconds,
            connectTimeoutSeconds: $endpoint->connectTimeoutSeconds
        );

        return [
            'request' => $req,
            'body' => $body,
            'headers' => $headers,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ];
    }

    public function sendPrepared(DeliveryRequest $request): DeliveryResult
    {
        return $this->transport->send($request);
    }

    public function send(WebhookEndpoint $endpoint, WebhookEvent $event): DeliveryResult
    {
        $prepared = $this->prepare($endpoint, $event);
        return $this->sendPrepared($prepared['request']);
    }
}
