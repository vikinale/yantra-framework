<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class PinCode implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool {
        return (bool)preg_match('/^\d{6}$/', trim((string)$value));
    }
    public function message(string $field): string { return "{$field} must be a valid 6-digit PIN code."; }
}
