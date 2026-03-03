<?php
declare(strict_types=1);

namespace System\Security;

final class Crypto
{
    public static function randomBytes(int $length): string
    {
        if ($length <= 0) {
            throw new \InvalidArgumentException('Length must be > 0.');
        }
        return random_bytes($length);
    }

    public static function randomHex(int $bytes = 32): string
    {
        return bin2hex(self::randomBytes($bytes));
    }

    public static function hashEquals(string $known, string $user): bool
    {
        // Constant-time compare
        return hash_equals($known, $user);
    }

    public static function hmacSha256(string $data, string $key, bool $raw = true): string
    {
        if ($key === '') {
            throw new \InvalidArgumentException('HMAC key cannot be empty.');
        }
        return hash_hmac('sha256', $data, $key, $raw);
    }
}
