<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Events;

final class WebhookEvent
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $meta
     */
    private function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $occurredAt, // unix seconds
        public readonly array $payload,
        public readonly array $meta = []
    ) {}

    /**
     * Create a new event with a random id.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $meta
     */
    public static function create(string $type, array $payload, ?int $occurredAt = null, array $meta = []): self
    {
        $occurredAt = $occurredAt ?? time();
        $id = self::uuidv4();
        return new self($id, $type, $occurredAt, $payload, $meta);
    }

    private static function uuidv4(): string
    {
        $data = random_bytes(16);
        // version 4
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        // variant 10
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
