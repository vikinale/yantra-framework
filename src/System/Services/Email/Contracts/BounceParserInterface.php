<?php
declare(strict_types=1);

namespace System\Services\Email\Contracts;

use System\Services\Email\Bounce\BounceEvent;

interface BounceParserInterface
{
    /**
     * Parse a provider bounce payload into a normalized BounceEvent.
     * Return null if payload is not recognized.
     */
    public function parse(string $payload, array $headers = []): ?BounceEvent;
}
