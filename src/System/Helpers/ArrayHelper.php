<?php

namespace System\Helpers;

/**
 * Class ArrayHelper
 *
 * Provides utilities for working with arrays, including:
 * - dot-notation access
 * - deep setting/unsetting
 * - flattening
 * - mapping/filtering
 */
class ArrayHelper
{
    /**
     * Get value from array using dot notation.
     */
    public static function get(array $array, string $path, mixed $default = null): mixed
    {
        if ($path === '') {
            return $array;
        }

        $keys = explode('.', $path);

        foreach ($keys as $key) {
            if (!is_array($array) || !array_key_exists($key, $array)) {
                return $default;
            }
            $array = $array[$key];
        }

        return $array;
    }

    /**
     * Set value in array using dot notation.
     */
    public static function set(array &$array, string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $ref = &$array;

        foreach ($keys as $key) {
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }

        $ref = $value;
    }

    /**
     * Remove value from array using dot notation.
     */
    public static function forget(array &$array, string $path): void
    {
        $keys = explode('.', $path);
        $ref = &$array;

        while (count($keys) > 1) {
            $key = array_shift($keys);

            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                return;
            }

            $ref = &$ref[$key];
        }

        unset($ref[array_shift($keys)]);
    }

    /**
     * Flatten a multi-dimensional array with dot notation keys.
     */
    public static function flatten(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix === '' ? $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $result += self::flatten($value, $newKey);
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Map each value using callback (like array_map but preserves associative keys).
     */
    public static function map(array $array, callable $callback): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $result[$key] = $callback($value, $key);
        }

        return $result;
    }

    /**
     * Return first element that passes the optional callback.
     */
    public static function first(array $array, callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $array[array_key_first($array)] ?? $default;
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Return last element that passes the optional callback.
     */
    public static function last(array $array, callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $array[array_key_last($array)] ?? $default;
        }

        foreach (array_reverse($array, true) as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }
}
