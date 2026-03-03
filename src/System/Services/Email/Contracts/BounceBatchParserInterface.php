<?php
declare(strict_types=1);

namespace System\Services\Email\Contracts;

use System\Services\\Email\Bounce\BounceEvent;

interface BounceBatchParserInterface
{
    /**
     * Parse a provider payload that may contain multiple events.
     * Return null if payload is not recognized.
     *
     * @return BounceEvent[]|null
     */
    public function parseBatch(string $payload, array $headers = []): ?array;
}
