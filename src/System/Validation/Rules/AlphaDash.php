<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class AlphaDash implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]+$/', $value);
    }
    public function message(string $field): string { return "{$field} may only contain letters, numbers, dashes, and underscores."; }
}
