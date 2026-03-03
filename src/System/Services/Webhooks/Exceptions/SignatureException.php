<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Exceptions;

final class SignatureException extends \RuntimeException
{
    public static function missingHeader(string $name): self
    {
        return new self("Missing required webhook header: {$name}");
    }

    public static function invalidTimestamp(string $value): self
    {
        return new self("Invalid webhook timestamp: {$value}");
    }

    public static function timestampOutsideTolerance(int $timestamp, int $now, int $toleranceSeconds): self
    {
        return new self("Webhook timestamp outside tolerance. ts={$timestamp} now={$now} tolerance={$toleranceSeconds}s");
    }

    public static function missingSecret(string $endpointId): self
    {
        return new self("Missing webhook secret for endpointId={$endpointId}");
    }

    public static function signatureMismatch(): self
    {
        return new self("Webhook signature mismatch.");
    }

    public static function replayDetected(): self
    {
        return new self("Webhook replay detected.");
    }
}
