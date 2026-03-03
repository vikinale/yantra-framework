<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Json implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool {
        if (!is_string($value)) return false;
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }
    public function message(string $field): string { return "{$field} must be a valid JSON string."; }
}
