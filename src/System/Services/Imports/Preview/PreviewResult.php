<?php
declare(strict_types=1);

namespace System\Services\Imports\Preview;

final class PreviewResult
{
    /**
     * @param string[] $header
     * @param array<int,array<int,string>> $sampleRows
     * @param array<string,mixed> $suggestions
     */
    public function __construct(public readonly array $header, public readonly array $sampleRows, public readonly array $suggestions = []) {}
}
