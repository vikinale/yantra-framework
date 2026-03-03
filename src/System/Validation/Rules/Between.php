<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Between implements RuleInterface
{
    public function __construct(private float $min, private float $max) {}
    public function passes(string $field, mixed $value, array $data): bool {
        $v = match(true) {
            is_numeric($value) => $value,
            is_string($value) => strlen($value),
            is_array($value) => count($value),
            default => null
        };
        return $v !== null && $v >= $this->min && $v <= $this->max;
    }
    public function message(string $field): string { return "{$field} must be between {$this->min} and {$this->max}."; }
}
