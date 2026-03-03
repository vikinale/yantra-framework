<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Same implements RuleInterface
{
    public function __construct(private string $otherField) {}
    public function passes(string $field, mixed $value, array $data): bool {
        return isset($data[$this->otherField]) && $value === $data[$this->otherField];
    }
    public function message(string $field): string { return "{$field} matches {$this->otherField}."; }
}
