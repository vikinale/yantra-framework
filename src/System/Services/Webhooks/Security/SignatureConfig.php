<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Security;

final class SignatureConfig
{
    private function __construct(
        public readonly string $signatureHeader,
        public readonly string $timestampHeader,
        public readonly string $eventIdHeader,
        public readonly int $toleranceSeconds
    ) {}

    public static function defaults(): self
    {
        return new self(
            signatureHeader: 'X-Yantra-Signature',
            timestampHeader: 'X-Yantra-Timestamp',
            eventIdHeader: 'X-Yantra-Event-Id',
            toleranceSeconds: 300
        );
    }

    public function withToleranceSeconds(int $seconds): self
    {
        return new self($this->signatureHeader, $this->timestampHeader, $this->eventIdHeader, $seconds);
    }

    public function withHeaders(string $signatureHeader, string $timestampHeader, string $eventIdHeader): self
    {
        return new self($signatureHeader, $timestampHeader, $eventIdHeader, $this->toleranceSeconds);
    }
}
