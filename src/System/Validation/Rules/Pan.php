<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Pan implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool {
        return (bool)preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', strtoupper(trim((string)$value)));
    }
    public function message(string $field): string { return "{$field} must be a valid PAN number."; }
}
