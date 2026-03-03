<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class StartsWith implements RuleInterface
{
    public function __construct(private array $needles) {}
    public function passes(string $field, mixed $value, array $data): bool {
        if (!is_string($value)) return false;
        foreach ($this->needles as $needle) if (str_starts_with($value, $needle)) return true;
        return false;
    }
    public function message(string $field): string { return "{$field} must start with one of: " . implode(', ', $this->needles); }
}
