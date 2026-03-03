<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Mobile implements RuleInterface
{
    public function __construct(private string $code = 'any') { $this->code = strtoupper($code); }
    public function passes(string $field, mixed $value, array $data): bool {
        $n = ltrim(preg_replace('/[\s\-\(\)\+\.]/', '', (string)$value), '+');
        return match($this->code) {
            'IN' => (bool)preg_match('/^(?:91)?[6-9]\d{9}$/', $n),
            'US', 'CA' => (bool)preg_match('/^1?\d{10}$/', $n),
            'UK', 'GB' => (bool)preg_match('/^(?:447|07)\d{9}$/', $n),
            'AU' => (bool)preg_match('/^(?:614|04)\d{8}$/', $n),
            default => (bool)preg_match('/^\d{8,15}$/', $n)
        };
    }
    public function message(string $field): string { return "{$field} must be a valid {$this->code} mobile number."; }
}
