<?php
declare(strict_types=1);

namespace System\Services\Reporting\Export;

use DateTimeInterface;
use System\Services\Reporting\ReportResult;

final class CsvExporter
{
    /**
     * Write a report result to a CSV stream.
     *
     * @param resource $stream An open stream resource (e.g. fopen('php://output','w')).
     * @param array{delimiter?:string,enclosure?:string,escape?:string,include_header?:bool} $options
     */
    public function writeToStream(ReportResult $result, $stream, array $options = []): void
    {
        $delimiter = (string)($options['delimiter'] ?? ',');
        $enclosure = (string)($options['enclosure'] ?? '"');
        $escape = (string)($options['escape'] ?? '\\');
        $includeHeader = (bool)($options['include_header'] ?? true);

        $keys = [];
        $labels = [];
        foreach ($result->columns as $col) {
            $keys[] = $col['key'];
            $labels[] = $col['label'] ?? $col['key'];
        }

        if ($includeHeader) {
            fputcsv($stream, $labels, $delimiter, $enclosure, $escape);
        }

        foreach ($result->rows as $row) {
            $line = [];
            foreach ($keys as $k) {
                $line[] = $this->stringify($row[$k] ?? null);
            }
            fputcsv($stream, $line, $delimiter, $enclosure, $escape);
        }
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) return '';
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_int($value) || is_float($value)) return (string)$value;
        if ($value instanceof DateTimeInterface) return $value->format('c');
        if (is_string($value)) return $value;
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        if (is_object($value)) return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        return (string)$value;
    }
}
