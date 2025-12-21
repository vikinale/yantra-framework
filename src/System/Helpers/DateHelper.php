<?php

namespace System\Helpers;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Class DateHelper
 *
 * Lightweight date/time utilities built on DateTimeImmutable.
 * - Timezone-aware
 * - Safe operations (returns DateTimeImmutable)
 * - Human-readable diff
 */
class DateHelper
{
    /**
     * Return a DateTimeImmutable for "now" in given timezone or default system timezone.
     *
     * @param string|DateTimeZone|null $tz
     * @return DateTimeImmutable
     */
    public static function now(string|DateTimeZone|null $tz = null): DateTimeImmutable
    {
        $zone = self::normalizeTz($tz);
        return new DateTimeImmutable('now', $zone);
    }

    /**
     * Parse a date/time string into DateTimeImmutable.
     *
     * Examples:
     *   DateHelper::parse('2025-01-01 10:00', 'UTC');
     *   DateHelper::parse('2025-01-01T10:00:00+05:30');
     *
     * @param string $time
     * @param string|DateTimeZone|null $tz
     * @return DateTimeImmutable
     * @throws Exception on parse failure
     */
    public static function parse(string $time, string|DateTimeZone|null $tz = null): DateTimeImmutable
    {
        $zone = self::normalizeTz($tz);
        // If the string contains timezone info, DateTimeImmutable will respect it.
        $dt = new DateTimeImmutable($time, $zone);
        return $dt;
    }

    /**
     * Convert a DateTime/string to integer timestamp (seconds).
     *
     * @param DateTimeImmutable|string|int $value
     * @return int
     * @throws Exception
     */
    public static function toTimestamp(DateTimeImmutable|string|int $value): int
    {
        if ($value instanceof DateTimeImmutable) {
            return (int) $value->getTimestamp();
        }

        if (is_int($value)) {
            return $value;
        }

        $dt = self::parse($value);
        return (int) $dt->getTimestamp();
    }

    /**
     * Format a date/time value.
     *
     * @param DateTimeImmutable|string|int|null $value If null -> now()
     * @param string $format Date format (default: 'Y-m-d H:i:s')
     * @param string|DateTimeZone|null $tz
     * @return string
     * @throws Exception
     */
    public static function format(DateTimeImmutable|string|int|null $value = null, string $format = 'Y-m-d H:i:s', string|DateTimeZone|null $tz = null): string
    {
        $zone = self::normalizeTz($tz);

        if ($value === null) {
            return self::now($zone)->format($format);
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone($zone)->format($format);
        }

        if (is_int($value)) {
            return (new DateTimeImmutable('@' . $value))->setTimezone($zone)->format($format);
        }

        return self::parse($value, $zone)->format($format);
    }

    /**
     * Add days to a date/time and return DateTimeImmutable.
     *
     * @param DateTimeImmutable|string|null $value If null -> now()
     * @param int $days Positive or negative
     * @param string|DateTimeZone|null $tz
     * @return DateTimeImmutable
     * @throws Exception
     */
    public static function addDays(DateTimeImmutable|string|null $value = null, int $days = 0, string|DateTimeZone|null $tz = null): DateTimeImmutable
    {
        $zone = self::normalizeTz($tz);

        $dt = match (true) {
            $value instanceof DateTimeImmutable => $value->setTimezone($zone),
            is_string($value) => self::parse($value, $zone),
            default => self::now($zone),
        };

        if ($days === 0) {
            return $dt;
        }

        $sign = $days > 0 ? 'P' : 'P';
        $absDays = abs($days);
        $interval = new DateInterval("{$sign}{$absDays}D");

        return $days > 0 ? $dt->add($interval) : $dt->sub($interval);
    }

    /**
     * Human-friendly difference between two dates.
     *
     * Examples:
     *  - 2 days ago
     *  - in 3 hours
     *
     * @param DateTimeImmutable|string|null $from Defaults to now()
     * @param DateTimeImmutable|string|null $to Defaults to now()
     * @return string
     * @throws Exception
     */
    public static function diffHuman(DateTimeImmutable|string|null $from = null, DateTimeImmutable|string|null $to = null): string
    {
        $a = $from instanceof DateTimeImmutable ? $from : ($from ? self::parse($from) : self::now());
        $b = $to instanceof DateTimeImmutable ? $to : ($to ? self::parse($to) : self::now());

        // always compute $a -> $b
        $invert = ($a > $b);
        $diff = $a->diff($b);

        // mapping of largest unit
        if ($diff->y > 0) {
            $unit = $diff->y;
            $label = $unit === 1 ? 'year' : 'years';
        } elseif ($diff->m > 0) {
            $unit = $diff->m;
            $label = $unit === 1 ? 'month' : 'months';
        } elseif ($diff->d > 0) {
            $unit = $diff->d;
            $label = $unit === 1 ? 'day' : 'days';
        } elseif ($diff->h > 0) {
            $unit = $diff->h;
            $label = $unit === 1 ? 'hour' : 'hours';
        } elseif ($diff->i > 0) {
            $unit = $diff->i;
            $label = $unit === 1 ? 'minute' : 'minutes';
        } else {
            $unit = max(1, $diff->s);
            $label = $unit === 1 ? 'second' : 'seconds';
        }

        $when = $invert ? 'ago' : 'from now';
        // if exactly equal, return "just now"
        if ($diff->y === 0 && $diff->m === 0 && $diff->d === 0 && $diff->h === 0 && $diff->i === 0 && $diff->s === 0) {
            return 'just now';
        }

        return sprintf('%d %s %s', $unit, $label, $invert ? 'ago' : 'from now');
    }

    /**
     * Convert a timezone input into DateTimeZone instance.
     *
     * Accepts timezone string or DateTimeZone or null.
     *
     * @param string|DateTimeZone|null $tz
     * @return DateTimeZone
     * @throws Exception
     */
    protected static function normalizeTz(string|DateTimeZone|null $tz = null): DateTimeZone
    {
        if ($tz instanceof DateTimeZone) {
            return $tz;
        }

        if ($tz === null) {
            return new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        }

        return new DateTimeZone($tz);
    }
}
