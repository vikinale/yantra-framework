<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class IsBoolean implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool {
        return is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true);
    }
    public function message(string $field): string { return "{$field} must be a boolean."; }
}
