<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class MaxLength implements RuleInterface
{
    public function __construct(private int $max) {}
    public function passes(string $field, mixed $value, array $data): bool {
        return is_string($value) && strlen($value) <= $this->max;
    }
    public function message(string $field): string { return "{$field} may not be greater than {$this->max} characters."; }
}
