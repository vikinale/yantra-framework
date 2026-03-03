<?php
declare(strict_types=1);

namespace System\Services\Queue;

final class ReservedJob
{
    /** @param array<string,mixed> $payloadDecoded */
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly string $handler,
        public readonly array $payloadDecoded,
        public readonly int $attempts,
        public readonly int $reservedAtUnix,
        public readonly int $availableAtUnix
    ) {}
}
