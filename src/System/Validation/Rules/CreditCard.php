<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class CreditCard implements RuleInterface
{
    public function __construct(private bool $allowSpaces = true) {}
    public function passes(string $field, mixed $value, array $data): bool {
        $str = (string)$value;
        if ($this->allowSpaces) $str = preg_replace('/\s+/', '', $str);
        if (!preg_match('/^\d{13,19}$/', $str)) return false;
        
        // Luhn
        $sum = 0; $len = strlen($str); $parity = $len % 2;
        for ($i = 0; $i < $len; $i++) {
            $d = (int)$str[$i];
            if ($i % 2 === $parity) { $d *= 2; if ($d > 9) $d -= 9; }
            $sum += $d;
        }
        return $sum % 10 === 0;
    }
    public function message(string $field): string { return "{$field} must be a valid credit card number."; }
}
