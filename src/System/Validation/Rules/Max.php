<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Max implements RuleInterface
{
    public function __construct(private float $max) {}
    public function passes(string $field, mixed $value, array $data): bool {
        if (is_numeric($value)) return $value <= $this->max;
        if (is_string($value)) return strlen($value) <= $this->max;
        if (is_array($value)) return count($value) <= $this->max;
        return false;
    }
    public function message(string $field): string { return "{$field} may not be greater than {$this->max}."; }
}
