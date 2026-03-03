<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class RequiredWith implements RuleInterface
{
    public function __construct(private array $otherFields) {}

    public function passes(string $field, mixed $value, array $data): bool
    {
        $anyPresent = false;
        foreach ($this->otherFields as $otherField) {
            if (isset($data[$otherField]) && $data[$otherField] !== '' && $data[$otherField] !== null) {
                $anyPresent = true; break;
            }
        }
        if ($anyPresent) return $value !== null && $value !== '' && (!is_array($value) || !empty($value));
        return true;
    }
    public function message(string $field): string { return "{$field} is required when present fields match."; }
}
