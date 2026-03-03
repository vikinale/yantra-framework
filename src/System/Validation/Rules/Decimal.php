<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Decimal implements RuleInterface
{
    public function __construct(private int $scale = 2) {}
    public function passes(string $field, mixed $value, array $data): bool {
        if (!is_numeric($value)) return false;
        $str = (string)$value;
        if (str_contains($str, '.')) {
            return strlen(explode('.', $str)[1]) <= $this->scale;
        }
        return true;
    }
    public function message(string $field): string { return "{$field} must be a decimal with at most {$this->scale} places."; }
}
