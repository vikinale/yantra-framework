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
        public readonly int $durationMs,
        public readonly bool $blocked = false
    ) {}

    public static function networkError(string $error, int $durationMs): self
    {
        return new self(false, 0, '', [], $error, $durationMs);
    }

    /**
     * The request was rejected locally before being sent (e.g. SSRF-unsafe
     * URL). This is a permanent client-side failure and must not be retried.
     */
    public static function blocked(string $error): self
    {
        return new self(false, 0, '', [], $error, 0, true);
    }
}
