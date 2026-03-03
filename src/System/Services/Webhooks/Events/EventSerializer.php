<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Events;

/**
 * Converts WebhookEvent to a JSON payload.
 * Keep this stable because receivers depend on it.
 */
final class EventSerializer
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(WebhookEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type,
            'occurred_at' => $event->occurredAt,
            'payload' => $event->payload,
            'meta' => $event->meta,
        ];
    }

    public function toJson(WebhookEvent $event): string
    {
        $json = json_encode($this->toArray($event), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('Failed to encode webhook event JSON.');
        }
        return $json;
    }
}
