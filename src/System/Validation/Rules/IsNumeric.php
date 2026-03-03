<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class IsNumeric implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool { return is_numeric($value); }
    public function message(string $field): string { return "{$field} must be numeric."; }
}
