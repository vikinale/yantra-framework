<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Gte implements RuleInterface
{
    public function __construct(private string $other) {}
    public function passes(string $field, mixed $value, array $data): bool {
        $cmp = isset($data[$this->other]) ? $data[$this->other] : $this->other;
        if (is_numeric($value) && is_numeric($cmp)) return $value >= $cmp;
        return strcmp((string)$value, (string)$cmp) >= 0;
    }
    public function message(string $field): string { return "{$field} must be greater than or equal to {$this->other}."; }
}
