<?php
declare(strict_types=1);

namespace System\Security\Csp;

final class CspNonce
{
    private static ?string $nonce = null;

    public static function get(): string
    {
        if (self::$nonce === null) {
            self::$nonce = rtrim(
                strtr(base64_encode(random_bytes(16)), '+/', '-_'),
                '='
            );
        }
        return self::$nonce;
    }
}
