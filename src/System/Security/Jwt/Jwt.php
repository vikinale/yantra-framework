<?php
declare(strict_types=1);

namespace System\Security\Jwt;

use System\Security\Crypto;

final class Jwt
{
    public static function encodeHS256(array $payload, string $secret, array $header = []): string
    {
        if ($secret === '') {
            throw new JwtException('JWT secret cannot be empty.');
        }

        $h = $header + ['typ' => 'JWT', 'alg' => 'HS256'];

        $hJson = json_encode($h, JSON_UNESCAPED_SLASHES);
        $pJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if (!is_string($hJson) || !is_string($pJson)) {
            throw new JwtException('Failed to JSON encode.');
        }

        $h64 = self::b64urlEncode($hJson);
        $p64 = self::b64urlEncode($pJson);

        $data = $h64 . '.' . $p64;
        $sig  = Crypto::hmacSha256($data, $secret, true);

        return $data . '.' . self::b64urlEncode($sig);
    }

    /**
     * Returns payload array or null if invalid.
     * If $throw=true, throws JwtException instead of returning null.
     */
    public static function decodeHS256(
        string $jwt,
        string $secret,
        int $leewaySeconds = 0,
        bool $throw = false
    ): ?array {
        try {
            $jwt = trim($jwt);
            if ($jwt === '') {
                throw new JwtException('Empty token.');
            }

            $parts = explode('.', $jwt);
            if (count($parts) !== 3) {
                throw new JwtException('Token must have 3 parts.');
            }

            [$h64, $p64, $s64] = $parts;

            $headerJson  = self::b64urlDecode($h64);
            $payloadJson = self::b64urlDecode($p64);
            $sig         = self::b64urlDecode($s64);

            if ($headerJson === null || $payloadJson === null || $sig === null) {
                throw new JwtException('Invalid base64url.');
            }

            $header  = json_decode($headerJson, true);
            $payload = json_decode($payloadJson, true);

            if (!is_array($header) || !is_array($payload)) {
                throw new JwtException('Invalid JSON.');
            }

            if (($header['alg'] ?? null) !== 'HS256') {
                throw new JwtException('Unsupported alg.');
            }

            if ($secret === '') {
                throw new JwtException('JWT secret cannot be empty.');
            }

            $data     = $h64 . '.' . $p64;
            $expected = Crypto::hmacSha256($data, $secret, true);

            if (!Crypto::hashEquals($expected, $sig)) {
                throw new JwtException('Signature verification failed.');
            }

            self::validateTimes($payload, $leewaySeconds);

            return $payload;
        } catch (\Throwable $e) {
            if ($throw) {
                throw ($e instanceof JwtException) ? $e : new JwtException($e->getMessage(), 0, $e);
            }
            return null;
        }
    }

    /**
     * Validate standard claims: nbf, iat, exp
     */
    private static function validateTimes(array $payload, int $leewaySeconds): void
    {
        $now = time();

        if (isset($payload['nbf']) && is_numeric($payload['nbf'])) {
            if ($now + $leewaySeconds < (int)$payload['nbf']) {
                throw new JwtException('Token not active yet (nbf).');
            }
        }

        if (isset($payload['iat']) && is_numeric($payload['iat'])) {
            if ((int)$payload['iat'] > $now + $leewaySeconds) {
                throw new JwtException('Token issued in the future (iat).');
            }
        }

        if (isset($payload['exp']) && is_numeric($payload['exp'])) {
            if ($now - $leewaySeconds >= (int)$payload['exp']) {
                throw new JwtException('Token expired (exp).');
            }
        }
    }

    private static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): ?string
    {
        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($data, true);
        return ($out === false) ? null : $out;
    }
}
