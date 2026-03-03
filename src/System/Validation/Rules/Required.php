<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Required implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        if ($value === null) return false;
        if (is_string($value) && trim($value) === '') return false;
        if (is_array($value) && empty($value)) return false;
        return true;
    }
    public function message(string $field): string { return "{$field} is required."; }
}
