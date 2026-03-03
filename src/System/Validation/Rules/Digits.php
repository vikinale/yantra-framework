<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Digits implements RuleInterface
{
    public function __construct(private int $length) {}
    public function passes(string $field, mixed $value, array $data): bool {
        return !preg_match('/[^0-9]/', (string)$value) && strlen((string)$value) === $this->length;
    }
    public function message(string $field): string { return "{$field} must be {$this->length} digits."; }
}
