<?php

namespace System\Helpers;

use RuntimeException;

/**
 * Class SecurityHelper
 *
 * Provides lightweight security utilities:
 * - Hashing + verification
 * - UUID token generation
 * - Secure random strings
 * - HTML escaping + sanitization
 * - Constant-time comparisons
 */
class SecurityHelper
{
    /**
     * Create a secure hash of a value.
     *
     * @param string $value
     * @param string $algo sha256|sha512|bcrypt|argon2id
     * @return string
     */
    public static function hash(string $value, string $algo = 'sha256'): string
    {
        $algo = strtolower($algo);

        return match ($algo) {
            'bcrypt'   => password_hash($value, PASSWORD_BCRYPT),
            'argon2id' => password_hash($value, PASSWORD_ARGON2ID),
            'sha512'   => hash('sha512', $value),
            default    => hash('sha256', $value),
        };
    }

    /**
     * Verify a value against a hash.
     *
     * Supports SHA-* comparison and password_verify for bcrypt/argon2id hashes.
     */
    public static function verifyHash(string $value, string $hash): bool
    {
        // Detect bcrypt/argon2 automatically
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$argon2')) {
            return password_verify($value, $hash);
        }

        // SHA256/SHA512 manual compare
        $algo = strlen($hash) === 64 ? 'sha256' : (strlen($hash) === 128 ? 'sha512' : null);

        if ($algo !== null) {
            $computed = hash($algo, $value);
            return self::constantTimeEquals($computed, $hash);
        }

        // Unknown hash format
        return false;
    }

    /**
     * Generate a cryptographically secure random string.
     *
     * @param int $length
     * @return string
     */
    public static function random(int $length = 32): string
    {
        return substr(bin2hex(random_bytes($length)), 0, $length);
    }

    /**
     * Generate a UUID v4 (RFC 4122 compliant).
     *
     * @return string
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);

        // Set version to 4
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        // Set variant to RFC 4122
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return sprintf(
            '%08s-%04s-%04s-%04s-%12s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10))
        );
    }

    /**
     * Escape HTML to prevent XSS.
     *
     * @return string
     */
    public static function escape(string $html): string
    {
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Very lightweight HTML sanitizer.
     *
     * Removes script/style tags and dangerous attributes.
     * Safe for backend, form output, logs, and admin UI.
     */
    public static function cleanHtml(string $html): string
    {
        // Remove script/style tags entirely
        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#si', '', $html);

        // Remove on* attributes (onclick, onload, etc.)
        $html = preg_replace('#\son\w+="[^"]*"#i', '', $html);
        $html = preg_replace("#\son\w+='[^']*'#i", '', $html);

        // Remove javascript: URIs
        $html = preg_replace('#javascript:#i', '', $html);

        return $html ?? '';
    }

    /**
     * Remove non-printable characters often used in XSS payloads.
     */
    public static function removeXss(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';
    }
    
    /**
     * Return cryptographically secure random bytes.
     *
     * @throws RuntimeException
     */
    public static function randomBytes(int $length): string
    {
        if ($length <= 0) {
            throw new \InvalidArgumentException('length must be > 0');
        }
        if (!function_exists('random_bytes')) {
            throw new RuntimeException('random_bytes() not available');
        }
        return random_bytes($length);
    }

    /**
     * Return URL-safe base64 (base64url) for binary data.
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode a base64url string. Returns null on invalid input.
     */
    public static function base64UrlDecode(string $str): ?string
    {
        $remainder = strlen($str) % 4;
        if ($remainder > 0) {
            $padlen = 4 - $remainder;
            $str .= str_repeat('=', $padlen);
        }
        $decoded = base64_decode(strtr($str, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * HMAC-SHA256 over data, returning base64url signature (binary HMAC -> base64url).
     */
    public static function hmac(string $data, string $key): string
    {
        $raw = hash_hmac('sha256', $data, $key, true);
        return self::base64UrlEncode($raw);
    }

    /**
     * Constant-time string compare wrapper (already present as constantTimeEquals).
     * Keep for compatibility.
     */
    public static function constantTimeEquals(string $a, string $b): bool
    {
        if (function_exists('hash_equals')) {
            return hash_equals($a, $b);
        }
        $la = strlen($a);
        $lb = strlen($b);
        if ($la !== $lb) return false;
        $res = 0;
        for ($i = 0; $i < $la; $i++) {
            $res |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $res === 0;
    }
}
