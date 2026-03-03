<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Aadhaar implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool {
        $str = preg_replace('/\s+/', '', (string)$value);
        if (!preg_match('/^\d{12}$/', $str) || in_array($str[0], ['0', '1'])) return false;
        
        // Verhoeff Algorithm
        $d = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],[5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]];
        $p = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],[9,4,5,3,1,2,6,8,7,0],[4,2,8,6,5,7,3,9,0,1],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
        $c = 0; $len = strlen($str);
        for ($i = 0; $i < $len; $i++) $c = $d[$c][$p[($len - $i) % 8][(int)$str[$i]]];
        return $c === 0;
    }
    public function message(string $field): string { return "{$field} must be a valid Aadhaar number."; }
}
