<?php
declare(strict_types=1);

namespace System\Helpers;

use InvalidArgumentException;
use JsonException;

/**
 * JsonHelper (Yantra optimized)
 *
 * Goals:
 *  - Predictable JSON encode/decode with strong defaults
 *  - Clear separation between "throwing" and "non-throwing" APIs
 *  - Robust error context without leaking huge payloads
 */
final class JsonHelper
{
    /**
     * Decode JSON string.
     *
     * @param string $json
     * @param bool   $assoc          true => array, false => object
     * @param int    $depth
     * @param int    $flags          json_decode flags (JSON_THROW_ON_ERROR recommended)
     * @param bool   $allowEmpty     if true, "" (after trim) returns [] or stdClass
     *
     * @return mixed
     *
     * @throws InvalidArgumentException
     */
    public static function decode(
        string $json,
        bool $assoc = true,
        int $depth = 512,
        int $flags = JSON_THROW_ON_ERROR,
        bool $allowEmpty = true
    ): mixed {
        $trimmed = trim($json);

        if ($trimmed === '') {
            if ($allowEmpty) {
                return $assoc ? [] : new \stdClass();
            }
            throw new InvalidArgumentException('Invalid JSON: empty string.');
        }

        try {
            return json_decode($trimmed, $assoc, $depth, $flags);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                self::buildDecodeError($e, $trimmed),
                0,
                $e
            );
        }
    }

    /**
     * Decode JSON string but do not throw.
     *
     * @param string     $json
     * @param bool       $assoc
     * @param mixed      $default
     * @param int        $depth
     * @param int        $flags
     * @param bool       $allowEmpty
     */
    public static function tryDecode(
        string $json,
        bool $assoc = true,
        mixed $default = null,
        int $depth = 512,
        int $flags = JSON_THROW_ON_ERROR,
        bool $allowEmpty = true
    ): mixed {
        try {
            return self::decode($json, $assoc, $depth, $flags, $allowEmpty);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Convenience: decode a HTTP request body.
     * Typically you want allowEmpty=true to treat empty body as [].
     */
    public static function decodeBody(
        string $body,
        bool $assoc = true,
        bool $allowEmpty = true
    ): mixed {
        return self::decode($body, $assoc, 512, JSON_THROW_ON_ERROR, $allowEmpty);
    }

    /**
     * Encode value to JSON string safely.
     *
     * Defaults:
     *  - throw on error
     *  - do not escape slashes/unicode
     *
     * @throws InvalidArgumentException
     */
    public static function encode(
        mixed $value,
        bool $pretty = false,
        int $flags = 0,
        int $depth = 512
    ): string {
        $options =
            JSON_THROW_ON_ERROR |
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            $flags;

        if ($pretty) {
            $options |= JSON_PRETTY_PRINT;
        }

        try {
            $json = json_encode($value, $options, $depth);
            // json_encode returns string when JSON_THROW_ON_ERROR is set, but be defensive
            if (!is_string($json)) {
                throw new InvalidArgumentException('JSON encode failed: unknown error.');
            }
            return $json;
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                'JSON encode failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public static function pretty(mixed $value, int $flags = 0, int $depth = 512): string
    {
        return self::encode($value, true, $flags, $depth);
    }

    /**
     * Quick validation: returns true if $json is valid JSON.
     * Empty string is treated as valid only if $allowEmpty=true.
     */
    public static function isValid(string $json, bool $allowEmpty = true): bool
    {
        $trimmed = trim($json);

        if ($trimmed === '') {
            return $allowEmpty;
        }

        try {
            json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Create a safe error message including a small snippet for debugging.
     */
    private static function buildDecodeError(JsonException $e, string $json): string
    {
        $snippet = $json;

        // Avoid logging huge payloads; keep first 200 chars
        if (strlen($snippet) > 200) {
            $snippet = substr($snippet, 0, 200) . '…';
        }

        // Avoid newlines causing messy logs
        $snippet = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $snippet);

        return 'Invalid JSON: ' . $e->getMessage() . ' | snippet="' . $snippet . '"';
    }
}
