<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Lowercase implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool {
        return is_string($value) && $value === mb_strtolower($value);
    }
    public function message(string $field): string { return "{$field} must be lowercase."; }
}
