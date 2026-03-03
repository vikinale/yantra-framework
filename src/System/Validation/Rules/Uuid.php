<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Uuid implements RuleInterface
{
    public function __construct(private ?int $version = null) {}
    public function passes(string $field, mixed $value, array $data): bool {
        if (!is_string($value)) return false;
        if ($this->version) {
             return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-' . $this->version . '[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
        }
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
    public function message(string $field): string { return "{$field} must be a valid UUID."; }
}
