<?php
declare(strict_types=1);

namespace System\Services\Queue;

final class JobPayload
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $meta
     */
    public function __construct(
        public readonly string $handler,
        public readonly array $data = [],
        public readonly string $queue = 'default',
        public readonly int $maxAttempts = 5,
        public readonly int $timeoutSeconds = 60,
        public readonly array $meta = []
    ) {}
}
