<?php

namespace System\Helpers;

/**
 * Class MathHelper
 *
 * Common math utilities used across Yantra.
 *
 * All methods are static. Numeric inputs accept int|float where applicable.
 */
class MathHelper
{
    /**
     * Calculate percentage safely.
     *
     * Example: percentage(25, 200) => 12.5
     *
     * @param float|int $value
     * @param float|int $total
     * @param int $precision Number of decimal places in the returned value
     * @return float
     */
    public static function percentage(float|int $value, float|int $total, int $precision = 2): float
    {
        $value = (float) $value;
        $total = (float) $total;

        if ($total == 0.0) {
            return 0.0;
        }

        $raw = ($value / $total) * 100.0;
        return self::roundTo($raw, $precision);
    }

    /**
     * Arithmetic mean (average) of numeric array.
     *
     * Returns 0.0 for empty arrays.
     *
     * @param array $numbers Array of numbers (ints or floats)
     * @param int $precision Round result to this many decimals, -1 to skip rounding
     * @return float
     */
    public static function average(array $numbers, int $precision = 2): float
    {
        $count = count($numbers);
        if ($count === 0) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($numbers as $n) {
            $sum += (float) $n;
        }

        $avg = $sum / $count;

        return $precision >= 0 ? self::roundTo($avg, $precision) : $avg;
    }

    /**
     * Median of numeric array.
     *
     * For even count returns the mean of the two middle values.
     * Returns 0.0 for empty arrays.
     *
     * @param array $numbers
     * @param int $precision Round result to this many decimals, -1 to skip rounding
     * @return float
     */
    public static function median(array $numbers, int $precision = 2): float
    {
        $count = count($numbers);
        if ($count === 0) {
            return 0.0;
        }

        $vals = array_values($numbers);
        sort($vals, SORT_NUMERIC);

        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            $result = (float) $vals[$mid];
        } else {
            $result = ((float) $vals[$mid - 1] + (float) $vals[$mid]) / 2.0;
        }

        return $precision >= 0 ? self::roundTo($result, $precision) : $result;
    }

    /**
     * Clamp a value between min and max.
     *
     * @param float|int $value
     * @param float|int $min
     * @param float|int $max
     * @return float|int
     */
    public static function clamp(float|int $value, float|int $min, float|int $max): float|int
    {
        if ($min > $max) {
            // Swap to be defensive
            [$min, $max] = [$max, $min];
        }

        if ($value < $min) {
            return $min;
        }

        if ($value > $max) {
            return $max;
        }

        return $value;
    }

    /**
     * Round a number to given precision.
     *
     * Supports negative precision to round to tens/hundreds (e.g. -1 => 10s).
     *
     * @param float|int $value
     * @param int $precision
     * @return float
     */
    public static function roundTo(float|int $value, int $precision = 2): float
    {
        if ($precision === 0) {
            return round($value);
        }

        // For negative precision, use pow(10, -precision) behavior in round()
        return round($value, $precision, PHP_ROUND_HALF_UP);
    }

    /**
     * Greatest common divisor (Euclidean algorithm).
     *
     * Works with integers. Returns absolute GCD.
     *
     * @param int $a
     * @param int $b
     * @return int
     */
    public static function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);

        if ($a === 0) return $b;
        if ($b === 0) return $a;

        while ($b !== 0) {
            $t = $a % $b;
            $a = $b;
            $b = $t;
        }

        return $a;
    }

    /**
     * Least common multiple.
     *
     * @param int $a
     * @param int $b
     * @return int
     */
    public static function lcm(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return (int) (abs($a) / self::gcd($a, $b) * abs($b));
    }
}
