<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Ifsc implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool {
        return (bool)preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper(trim((string)$value)));
    }
    public function message(string $field): string { return "{$field} must be a valid IFSC code."; }
}
