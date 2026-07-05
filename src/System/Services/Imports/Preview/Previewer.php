<?php
declare(strict_types=1);

namespace System\Services\Imports\Preview;

use System\Services\Imports\Csv\CsvReader;

final class Previewer
{
    public function preview(string $csvPath, int $sample = 10): PreviewResult
    {
        $reader = new CsvReader($csvPath);
        $header = [];
        $rows = [];
        foreach ($reader as $n => $row) {
            if ($n === 1) { $header = $row; continue; }
            $rows[] = $row;
            if (count($rows) >= $sample) break;
        }
        return new PreviewResult($header, $rows, []);
    }
}
