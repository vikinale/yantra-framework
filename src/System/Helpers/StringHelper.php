<?php

namespace System\Helpers;

/**
 * Class StringHelper
 *
 * Provides common string utilities used throughout Yantra.
 * All methods are static, UTF-8 safe, and null-safe.
 */
class StringHelper
{
    /**
     * Check if a string contains another string.
     */
    public static function contains(?string $haystack, string $needle): bool
    {
        if ($haystack === null || $needle === '') {
            return false;
        }
        return mb_strpos($haystack, $needle) !== false;
    }

    /**
     * Check if a string starts with a given prefix.
     */
    public static function startsWith(?string $haystack, string $needle): bool
    {
        if ($haystack === null) return false;
        return mb_substr($haystack, 0, mb_strlen($needle)) === $needle;
    }

    /**
     * Check if a string ends with a given suffix.
     */
    public static function endsWith(?string $haystack, string $needle): bool
    {
        if ($haystack === null) return false;
        $len = mb_strlen($needle);
        if ($len === 0) return true;
        return mb_substr($haystack, -$len) === $needle;
    }

    /**
     * Generate a URL-friendly slug.
     */
    public static function slug(string $string, string $separator = '-'): string
    {
        // Convert to ASCII (basic fallback)
        $string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);

        // Lowercase
        $string = strtolower($string);

        // Replace non-alphanumerics with separator
        $string = preg_replace('/[^a-z0-9]+/i', $separator, $string);

        // Trim duplicates
        $string = trim($string, $separator);

        return $string ?: 'n-a';
    }

    /**
     * Generate a random string.
     */
    public static function random(int $length = 16): string
    {
        return substr(bin2hex(random_bytes($length)), 0, $length);
    }

    /**
     * Limit a string by character length.
     */
    public static function limit(string $string, int $limit, string $suffix = '...'): string
    {
        if (mb_strlen($string) <= $limit) {
            return $string;
        }
        return mb_substr($string, 0, $limit) . $suffix;
    }

    /**
     * Convert a string to snake_case.
     */
    public static function snake(string $string): string
    {
        $string = preg_replace('/[A-Z]/', '_$0', $string);
        $string = strtolower($string);
        return ltrim($string, '_');
    }

    /**
     * Convert a string to camelCase.
     */
    public static function camel(string $string): string
    {
        $string = str_replace(['-', '_'], ' ', $string);
        $string = ucwords($string);
        $string = lcfirst(str_replace(' ', '', $string));
        return $string;
    }

    /**
     * Convert a string to StudlyCase.
     */
    public static function studly(string $string): string
    {
        $string = str_replace(['-', '_'], ' ', $string);
        return str_replace(' ', '', ucwords($string));
    }
}
