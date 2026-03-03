<?php
declare(strict_types=1);

namespace System\Services\Reporting\Export;

use DateTimeInterface;
use System\Services\\Reporting\ReportResult;

final class JsonExporter
{
    /**
     * Convert a report result to an array suitable for JSON encoding.
     *
     * Notes:
     * - Rows are normalized to JSON-safe values (e.g., DateTime => ISO string).
     * - By default, limits rows to avoid accidental huge payloads.
     *
     * @return array<string, mixed>
     */
    public function toArray(ReportResult $result, int $maxRows = 1000): array
    {
        $rows = [];
        $i = 0;
        foreach ($result->rows as $row) {
            $rows[] = $this->normalizeRow($row);
            $i++;
            if ($i >= $maxRows) {
                break;
            }
        }

        return [
            'columns' => $result->columns,
            'rows' => $rows,
            'summary' => $this->normalizeValue($result->summary),
            'meta' => $result->meta + [
                'rows_truncated' => $i >= $maxRows,
                'max_rows' => $maxRows,
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private function normalizeRow(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = $this->normalizeValue($v);
        }
        return $out;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('c');
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalizeValue($v);
            }
            return $out;
        }
        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }
            // last resort
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: (string)get_class($value);
        }
        return (string)$value;
    }
}
