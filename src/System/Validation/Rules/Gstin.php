<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Gstin implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool {
        $str = strtoupper(trim((string)$value));
        if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/', $str)) return false;
        $state = (int)substr($str, 0, 2);
        return $state >= 1 && $state <= 37;
    }
    public function message(string $field): string { return "{$field} must be a valid GSTIN."; }
}
