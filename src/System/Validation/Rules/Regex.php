<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Regex implements RuleInterface
{
    public function __construct(private string $pattern) {}
    public function passes(string $field, mixed $value, array $data): bool {
        return is_string($value) && preg_match($this->pattern, $value) > 0;
    }
    public function message(string $field): string { return "{$field} format is invalid."; }
}
