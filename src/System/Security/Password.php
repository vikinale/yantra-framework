<?php
declare(strict_types=1);

namespace System\Security;

final class Password
{
    /**
     * Prefer Argon2id when available; fall back to bcrypt.
     */
    public static function hash(string $password, array $options = []): string
    {
        if ($password === '') {
            throw new \InvalidArgumentException('Password cannot be empty.');
        }

        $algo = \defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;

        // Safe defaults; allow override via $options
        if ($algo === PASSWORD_BCRYPT) {
            $options = $options + ['cost' => 12];
        } else {
            // Argon2id defaults are OK; callers may override
            $options = $options + [
                'memory_cost' => 1 << 17, // 131072 KiB (128MB)
                'time_cost'   => 4,
                'threads'     => 2,
            ];
        }

        $hash = password_hash($password, $algo, $options);
        if ($hash === false) {
            throw new \RuntimeException('Failed to hash password.');
        }
        return $hash;
    }

    public static function verify(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash, array $options = []): bool
    {
        if ($hash === '') {
            return false;
        }

        $algo = \defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;

        if ($algo === PASSWORD_BCRYPT) {
            $options = $options + ['cost' => 12];
        } else {
            $options = $options + [
                'memory_cost' => 1 << 17,
                'time_cost'   => 4,
                'threads'     => 2,
            ];
        }

        return password_needs_rehash($hash, $algo, $options);
    }
}
