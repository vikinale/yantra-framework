<?php
declare(strict_types=1);

namespace System\Services\Imports\Mapping;

final class ColumnMap
{
    /** @param array<string,string> $map field => columnName */
    public function __construct(public readonly array $map, public readonly bool $caseInsensitive = true) {}

    /**
     * @param string[] $header
     * @param string[] $row
     * @return array<string,mixed>
     */
    public function apply(array $header, array $row): array
    {
        $idx=[];
        foreach ($header as $i=>$h) {
            $k = $this->caseInsensitive ? mb_strtolower($h) : $h;
            $idx[$k] = $i;
        }
        $out=[];
        foreach ($this->map as $field=>$column) {
            $k = $this->caseInsensitive ? mb_strtolower($column) : $column;
            $i = $idx[$k] ?? null;
            $out[$field] = ($i !== null && array_key_exists($i,$row)) ? $row[$i] : null;
        }
        return $out;
    }
}
