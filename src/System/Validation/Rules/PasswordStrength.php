<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class PasswordStrength implements RuleInterface
{
    public function __construct(private int $min = 8, private int $up = 1, private int $low = 1, private int $dig = 1, private int $sym = 1) {}
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (!is_string($value) || strlen($value) < $this->min) return false;
        if (preg_match_all('/[A-Z]/', $value) < $this->up) return false;
        if (preg_match_all('/[a-z]/', $value) < $this->low) return false;
        if (preg_match_all('/[0-9]/', $value) < $this->dig) return false;
        if (preg_match_all('/[^A-Za-z0-9]/', $value) < $this->sym) return false;
        return true;
    }
    public function message(string $field): string { return "{$field} does not meet complexity requirements."; }
}
