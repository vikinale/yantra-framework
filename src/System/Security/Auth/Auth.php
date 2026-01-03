<?php
declare(strict_types=1);

namespace System\Security\Auth;

use System\Security\SessionGuard;

final class Auth
{
    public static function check(): bool
    {
        SessionGuard::ensureStarted();
        return isset($_SESSION['auth']['uid']) && $_SESSION['auth']['uid'] !== '';
    }

    public static function id(): ?string
    {
        SessionGuard::ensureStarted();
        $uid = $_SESSION['auth']['uid'] ?? null;
        return is_string($uid) && $uid !== '' ? $uid : null;
    }

    /** @return string[] */
    public static function roles(): array
    {
        SessionGuard::ensureStarted();
        $roles = $_SESSION['auth']['roles'] ?? [];
        if (is_string($roles)) $roles = [$roles];
        if (!is_array($roles)) return [];
        return array_values(array_unique(array_map('strval', $roles)));
    }

    public static function hasRole(string $role): bool
    {
        return in_array($role, self::roles(), true);
    }
}
