<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Delivery;

final class DeliveryResult
{
    /**
     * @param array<string,string> $responseHeaders
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $statusCode,
        public readonly string $responseBody,
        public readonly array $responseHeaders,
        public readonly ?string $error,
        public readonly int $durationMs
    ) {}

    public static function networkError(string $error, int $durationMs): self
    {
        return new self(false, 0, '', [], $error, $durationMs);
    }
}
