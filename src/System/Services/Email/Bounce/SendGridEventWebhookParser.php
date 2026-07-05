<?php
declare(strict_types=1);

namespace System\Services\Email\Bounce;

use System\Services\Email\Contracts\BounceBatchParserInterface;

/**
 * Parse SendGrid Event Webhook payloads (JSON array of events) and normalize
 * bounce-like events into BounceEvent records.
 *
 * The application is responsible for wiring the inbound HTTP route and for
 * signature verification (optional) before calling the processor.
 */
final class SendGridEventWebhookParser implements BounceBatchParserInterface
{
    /**
     * Which SendGrid events should be converted into BounceEvent.
     *
     * @var array<string,true>
     */
    private array $supported;

    public function __construct(?array $supportedEvents = null)
    {
        // Default to common deliverability and suppression-relevant events.
        $events = $supportedEvents ?? [
            'bounce',
            'blocked',
            'dropped',
            'deferred',
            'spamreport',
            'unsubscribe',
            'group_unsubscribe',
        ];
        $this->supported = array_fill_keys(array_map('strval', $events), true);
    }

    public function parseBatch(string $payload, array $headers = []): ?array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || empty($decoded)) {
            return null;
        }

        // SendGrid sends an array of event objects.
        if (array_keys($decoded) !== range(0, count($decoded) - 1)) {
            return null;
        }

        $out = [];
        foreach ($decoded as $evt) {
            if (!is_array($evt)) continue;
            $eventName = isset($evt['event']) ? strtolower((string)$evt['event']) : '';
            if ($eventName === '' || !isset($this->supported[$eventName])) continue;

            $email = (string)($evt['email'] ?? '');
            if ($email === '') continue;

            [$type, $reason] = $this->classify($eventName, $evt);

            // Prefer sg_message_id (correlates to X-Message-ID / SMTP IDs); fallback to sg_event_id.
            $messageId = null;
            if (isset($evt['sg_message_id'])) $messageId = (string)$evt['sg_message_id'];
            elseif (isset($evt['smtp-id'])) $messageId = (string)$evt['smtp-id'];
            elseif (isset($evt['sg_event_id'])) $messageId = (string)$evt['sg_event_id'];

            $timestamp = null;
            if (isset($evt['timestamp'])) {
                $timestamp = is_numeric($evt['timestamp']) ? (int)$evt['timestamp'] : null;
            }

            $out[] = new BounceEvent(
                email: $email,
                type: $type,
                provider: 'sendgrid',
                reason: $reason,
                messageId: $messageId,
                timestamp: $timestamp,
                raw: $evt
            );
        }

        return empty($out) ? null : $out;
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function classify(string $eventName, array $evt): array
    {
        $reason = isset($evt['reason']) ? (string)$evt['reason'] : null;
        $status = isset($evt['status']) ? (string)$evt['status'] : null;
        $response = isset($evt['response']) ? (string)$evt['response'] : null;

        $detail = $reason ?? $response ?? $status ?? null;

        return match ($eventName) {
            'bounce', 'blocked', 'dropped' => ['hard', $detail ?? $eventName],
            'deferred' => ['soft', $detail ?? $eventName],
            'spamreport' => ['complaint', $detail ?? 'spamreport'],
            'unsubscribe', 'group_unsubscribe' => ['unknown', $detail ?? $eventName],
            default => ['unknown', $detail ?? $eventName],
        };
    }
}
