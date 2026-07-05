<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Honeypot implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
    public function message(string $field): string { return "Spam detected."; }
}
