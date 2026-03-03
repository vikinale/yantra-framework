<?php
declare(strict_types=1);

namespace System\Services\Reporting;

/**
 * UI-agnostic report result.
 *
 * - columns define presentation metadata (labels/types)
 * - rows is iterable to support streaming
 */
final class ReportResult
{
    /**
     * @var list<array{key:string,label:string,type?:string,format?:string}>
     */
    public readonly array $columns;

    /** @var iterable<array<string, mixed>> */
    public readonly iterable $rows;

    /** @var array<string, mixed> */
    public readonly array $summary;

    /** @var array<string, mixed> */
    public readonly array $meta;

    /**
     * @param list<array{key:string,label:string,type?:string,format?:string}> $columns
     * @param iterable<array<string, mixed>> $rows
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $meta
     */
    public function __construct(array $columns, iterable $rows, array $summary = [], array $meta = [])
    {
        $this->columns = $columns;
        $this->rows = $rows;
        $this->summary = $summary;
        $this->meta = $meta;
    }
}
