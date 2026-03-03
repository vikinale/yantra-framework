<?php
declare(strict_types=1);

namespace System\Testing\Assertions;

class Diff
{
    public static function arrayDiff(array $expected, array $actual): array
    {
        $diff = [];
        
        // Simple recursive diff
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)) {
                $diff[$key] = ['expected' => $value, 'actual' => '(missing)'];
            } elseif (is_array($value) && is_array($actual[$key])) {
                $subDiff = self::arrayDiff($value, $actual[$key]);
                if (!empty($subDiff)) {
                    $diff[$key] = $subDiff;
                }
            } elseif ($value !== $actual[$key]) {
                $diff[$key] = ['expected' => $value, 'actual' => $actual[$key]];
            }
        }
        
        foreach ($actual as $key => $value) {
            if (!array_key_exists($key, $expected)) {
                $diff[$key] = ['expected' => '(missing)', 'actual' => $value];
            }
        }
        
        return $diff;
    }
}
