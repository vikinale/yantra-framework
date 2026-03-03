<?php
declare(strict_types=1);

namespace System\Services\Imports;

final class ImportContext
{
    /** @param array<string,mixed> $extras */
    public function __construct(public readonly string $importId, public readonly array $extras = []) {}
}
