<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Min implements RuleInterface
{
    public function __construct(private float $min) {}
    public function passes(string $field, mixed $value, array $data): bool {
        if (is_numeric($value)) return $value >= $this->min;
        if (is_string($value)) return strlen($value) >= $this->min;
        if (is_array($value)) return count($value) >= $this->min;
        return false;
    }
    public function message(string $field): string { return "{$field} must be at least {$this->min}."; }
}
