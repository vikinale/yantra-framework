<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class KeyExists implements RuleInterface
{
    public function __construct(private array $keys) {}

    public function passes(string $field, mixed $value, array $data): bool
    {
        if (!is_array($value)) return false;
        foreach ($this->keys as $key) if (!array_key_exists($key, $value)) return false;
        return true;
    }
    public function message(string $field): string { return "{$field} must contain required keys."; }
}
