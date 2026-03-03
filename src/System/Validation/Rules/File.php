<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class File implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (!is_array($value) || !isset($value['tmp_name'])) return false;
        return is_uploaded_file($value['tmp_name']);
    }
    public function message(string $field): string { return "{$field} must be a valid file."; }
}
