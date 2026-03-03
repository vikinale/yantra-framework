<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class DigitsBetween implements RuleInterface
{
    public function __construct(private int $min, private int $max) {}
    public function passes(string $field, mixed $value, array $data): bool {
        $len = strlen((string)$value);
        return !preg_match('/[^0-9]/', (string)$value) && $len >= $this->min && $len <= $this->max;
    }
    public function message(string $field): string { return "{$field} must be between {$this->min} and {$this->max} digits."; }
}
