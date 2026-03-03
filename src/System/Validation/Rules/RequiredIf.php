<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class RequiredIf implements RuleInterface
{
    public function __construct(private string $otherField, private array $values) {}

    public function passes(string $field, mixed $value, array $data): bool
    {
        $otherValue = $data[$this->otherField] ?? null;
        if (in_array((string)$otherValue, $this->values, false)) {
            return $value !== null && $value !== '' && (!is_array($value) || !empty($value));
        }
        return true;
    }
    public function message(string $field): string { return "{$field} is required when {$this->otherField} matches values."; }
}
