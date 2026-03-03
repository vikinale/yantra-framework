<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class HexColor implements RuleInterface
{
    public function __construct(private bool $allowShort = true) {}
    public function passes(string $field, mixed $value, array $data): bool {
        $str = (string)$value;
        return (bool)(preg_match('/^#[0-9A-Fa-f]{6}$/', $str) || ($this->allowShort && preg_match('/^#[0-9A-Fa-f]{3}$/', $str)));
    }
    public function message(string $field): string { return "{$field} must be a valid hex color."; }
}
