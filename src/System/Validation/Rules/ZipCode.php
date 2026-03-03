<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class ZipCode implements RuleInterface
{
    public function __construct(private string $code = 'any') { $this->code = strtoupper($code); }
    public function passes(string $field, mixed $value, array $data): bool {
        $v = trim((string)$value);
        return match($this->code) {
            'US' => (bool)preg_match('/^\d{5}(-\d{4})?$/', $v),
            'CA' => (bool)preg_match('/^[A-Z]\d[A-Z]\s?\d[A-Z]\d$/i', $v),
            'UK', 'GB' => (bool)preg_match('/^[A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2}$/i', $v),
            'AU' => (bool)preg_match('/^\d{4}$/', $v),
            'DE', 'FR' => (bool)preg_match('/^\d{5}$/', $v),
            default => (bool)preg_match('/^[A-Z0-9\s\-]{3,10}$/i', $v)
        };
    }
    public function message(string $field): string { return "{$field} must be a valid postal code."; }
}
