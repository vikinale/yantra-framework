<?php
declare(strict_types=1);

namespace System\Http;

use InvalidArgumentException;
use JsonException;

/**
 * System\Http\Json
 *
 * HTTP-focused JSON utilities:
 *  - strict decode with clear error context
 *  - tryDecode() for non-throwing flows
 *  - encode() defaults suited for APIs (unescaped unicode/slashes)
 *
 * Notes:
 *  - Does not read php://input directly to avoid hidden side effects.
 *    Pass request body string explicitly from your Request class.
 */
final class Json
{
    /**
     * Decode a JSON string (throws InvalidArgumentException on invalid JSON).
     *
     * @param string $json
     * @param bool   $assoc       true => array, false => object
     * @param int    $depth
     * @param int    $flags       json_decode flags (JSON_THROW_ON_ERROR recommended)
     * @param bool   $allowEmpty  if true, "" (after trim) returns [] or stdClass
     *
     * @return mixed
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
     * Decode JSON without throwing; returns $default on failure.
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
     * Convenience for request bodies:
     * - Typically allowEmpty=true so empty body becomes [].
     */
    public static function decodeBody(string $body, bool $assoc = true, bool $allowEmpty = true): mixed
    {
        return self::decode($body, $assoc, 512, JSON_THROW_ON_ERROR, $allowEmpty);
    }

    /**
     * Encode a value into JSON string with API-friendly defaults.
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
     * Validates JSON quickly. Empty string is valid only if $allowEmpty=true.
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

    private static function buildDecodeError(JsonException $e, string $json): string
    {
        $snippet = $json;

        // Cap to avoid huge payloads in logs
        if (strlen($snippet) > 200) {
            $snippet = substr($snippet, 0, 200) . '…';
        }

        // Avoid newline/tab issues in logs
        $snippet = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $snippet);

        return 'Invalid JSON: ' . $e->getMessage() . ' | snippet="' . $snippet . '"';
    }
}
