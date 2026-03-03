<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Confirmed implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool {
        return $this->passes_same($value, $data[$field . '_confirmation'] ?? null);
    }
    private function passes_same($v1, $v2): bool { return $v1 === $v2; }
    public function message(string $field): string { return "{$field} confirmation does not match."; }
}
