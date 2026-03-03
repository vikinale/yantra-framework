<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Distinct implements RuleInterface
{
    public function __construct(private bool $caseSensitive = true) {}

    public function passes(string $field, mixed $value, array $data): bool
    {
        if (!is_array($value)) return false;
        $values = $this->caseSensitive ? $value : array_map(fn($i) => is_string($i) ? strtolower($i) : $i, $value);
        return count($values) === count(array_unique($values, SORT_REGULAR));
    }
    public function message(string $field): string { return "{$field} has duplicate values."; }
}
