<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class FullName implements RuleInterface
{
    public function __construct(private int $minWords = 2) {}
    public function passes(string $field, mixed $value, array $data): bool {
        $v = trim((string)$value);
        if (!preg_match("/^[a-zA-Z\s\-'\.]+$/u", $v)) return false;
        $words = array_filter(preg_split('/\s+/', $v), fn($w) => strlen($w) > 0);
        return count($words) >= $this->minWords;
    }
    public function message(string $field): string { return "{$field} must contain a full name."; }
}
