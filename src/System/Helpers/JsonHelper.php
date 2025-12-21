<?php

declare(strict_types=1);

namespace System\Helpers;

use InvalidArgumentException;
use JsonException;

/**
 * Class JsonHelper
 *
 * Safe JSON encode/decode helper with exceptions, pretty-printing,
 * UTF-8 handling, and robust error messages.
 *
 * All methods are static and handle JSON errors cleanly.
 */
class JsonHelper
{
    /**
     * Decode a JSON string safely.
     *
     * @param string $json        JSON string to decode
     * @param bool   $assoc       Decode into associative array
     * @param int    $depth       Maximum structure depth
     * @param int    $flags       json_decode flags (default: JSON_THROW_ON_ERROR)
     *
     * @return mixed
     *
     * @throws InvalidArgumentException|JsonException
     */
    public static function decode(
        string $json,
        bool $assoc = true,
        int $depth = 512,
        int $flags = JSON_THROW_ON_ERROR
    ): mixed {
        if ($json === '') {
            // empty body = empty array/object depending on $assoc
            return $assoc ? [] : new \stdClass();
        }

        try {
            return json_decode($json, $assoc, $depth, $flags);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                'Invalid JSON: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Encode a value to JSON safely.
     *
     * @param mixed $value  Any value to encode
     * @param bool  $pretty Pretty print output
     * @param int   $flags  Additional flags
     * @param int   $depth  Maximum encode depth
     *
     * @return string
     *
     * @throws InvalidArgumentException|JsonException
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
            return json_encode($value, $options, $depth);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                'JSON encode failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Encode with pretty print convenience helper.
     *
     * @param mixed $value
     * @return string
     */
    public static function pretty(mixed $value): string
    {
        return self::encode($value, true);
    }

    /**
     * Check if string is valid JSON (without throwing).
     *
     * @param string $json
     * @return bool
     */
    public static function isValid(string $json): bool
    {
        try {
            json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
