<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class AlphaNum implements RuleInterface
{
    public function __construct(private bool $allowWhitespace = false) {}
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (!is_string($value)) return false;
        if ($this->allowWhitespace) return (bool)preg_match('/^[a-zA-Z0-9\s]+$/', $value);
        return ctype_alnum($value);
    }
    public function message(string $field): string { return "{$field} may only contain letters and numbers."; }
}
