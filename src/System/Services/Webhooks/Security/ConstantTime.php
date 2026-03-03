<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Security;

final class ConstantTime
{
    public static function equals(string $a, string $b): bool
    {
        if (function_exists('hash_equals')) {
            return hash_equals($a, $b);
        }
        // Fallback constant-time compare
        if (strlen($a) !== strlen($b)) return false;
        $res = 0;
        for ($i = 0, $len = strlen($a); $i < $len; $i++) {
            $res |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $res === 0;
    }
}
