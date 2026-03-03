<?php
declare(strict_types=1);

namespace System\Services\Email\Queue;

final class QueueJob
{
    public function __construct(
        public string $id,
        public string $type,
        public array $payload,
        public int $attempts = 0,
        public int $maxAttempts = 5,
        public int $availableAt = 0 // unix timestamp
    ) {}

    public static function make(string $type, array $payload, int $delaySeconds = 0, int $maxAttempts = 5): self
    {
        $id = self::uuid();
        $availableAt = time() + max(0, $delaySeconds);
        return new self($id, $type, $payload, 0, $maxAttempts, $availableAt);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
            'maxAttempts' => $this->maxAttempts,
            'availableAt' => $this->availableAt,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string)$a['id'],
            (string)$a['type'],
            is_array($a['payload'] ?? null) ? $a['payload'] : [],
            (int)($a['attempts'] ?? 0),
            (int)($a['maxAttempts'] ?? 5),
            (int)($a['availableAt'] ?? 0),
        );
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
