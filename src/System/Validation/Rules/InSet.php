<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class InSet implements RuleInterface
{
    public function __construct(private array $values) {}
    public function passes(string $field, mixed $value, array $data): bool {
        return in_array($value, $this->values, false); // Loose comparison common for form data
    }
    public function message(string $field): string { return "The selected {$field} is invalid."; }
}
