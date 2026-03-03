<?php
declare(strict_types=1);

namespace System\Testing\Data;

use RuntimeException;

class CsvDataSet extends DataSet
{
    public function __construct(string $path)
    {
        if (!file_exists($path)) {
            throw new RuntimeException("CSV file not found: {$path}");
        }

        $rows = array_map('str_getcsv', file($path));
        $header = array_shift($rows);

        if (!$header) {
            parent::__construct([]);
            return;
        }

        $data = [];
        $caseIndex = 1;

        foreach ($rows as $row) {
            // Combine header keys with row values
            // Handle case where row might be shorter than header
            $paddedRow = array_pad($row, count($header), null);
            $raw = array_combine($header, $paddedRow);
            
            $expanded = $this->expandDottedKeys($raw);

            // Ensure standard schema
            if (empty($expanded['case_id'])) {
                $expanded['case_id'] = sprintf('C%03d', $caseIndex);
            }
            if (!isset($expanded['skip'])) {
                $expanded['skip'] = '0';
            }
            // title fallback
            if (empty($expanded['title'])) {
                 $expanded['title'] = 'Case ' . $expanded['case_id'];
            }
            
            $data[] = $expanded;
            $caseIndex++;
        }

        parent::__construct($data);
    }

    private function expandDottedKeys(array $flat): array
    {
        $result = [];
        foreach ($flat as $key => $value) {
            if (str_contains($key, '.')) {
                $parts = explode('.', $key);
                $leaf = array_pop($parts);
                $cursor = &$result;
                
                foreach ($parts as $part) {
                    if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
                        $cursor[$part] = [];
                    }
                    $cursor = &$cursor[$part];
                }
                
                $cursor[$leaf] = $this->castValue($value);
            } else {
                $result[$key] = $this->castValue($value);
            }
        }
        return $result;
    }

    private function castValue(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        
        $lower = strtolower($value);
        if ($lower === 'true') return true;
        if ($lower === 'false') return false;
        if ($lower === 'null') return null;
        if (is_numeric($value)) {
             // Keep strictly numeric strings as numbers if they look like it? 
             // Requirement says "strict types", but CSVs are untyped.
             // Let's infer int/float for convenience, relying on PHP's flexible typing for now.
             // Or explicitly NOT cast to avoid precision loss on big IDs?
             // Safest is to keep strings unless obvious boolean/null.
             return $value + 0; // naive cast
        }
        return $value;
    }
}
