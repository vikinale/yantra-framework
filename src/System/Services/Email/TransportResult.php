<?php
declare(strict_types=1);

namespace System\Services\Email;

final class TransportResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $messageId = null,
        public readonly int $statusCode = 0,
        public readonly string $provider = 'unknown',
        public readonly array $debug = []
    ) {}
}
