<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Email implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
    public function message(string $field): string { return "{$field} must be a valid email address."; }
}
