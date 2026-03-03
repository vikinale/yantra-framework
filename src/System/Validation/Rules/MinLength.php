<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class MinLength implements RuleInterface
{
    public function __construct(private int $min) {}
    public function passes(string $field, mixed $value, array $data): bool {
        return is_string($value) && strlen($value) >= $this->min;
    }
    public function message(string $field): string { return "{$field} must be at least {$this->min} characters."; }
}
