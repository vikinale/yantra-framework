<?php
declare(strict_types=1);

namespace System\Validation\Util;

final class Arr
{
    /** @param array<string,mixed> $data */
    public static function has(array $data, string $path): bool
    {
        $segments = explode('.', $path);
        $cur = $data;
        foreach ($segments as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) return false;
            $cur = $cur[$seg];
        }
        return true;
    }

    /** @param array<string,mixed> $data */
    public static function get(array $data, string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $cur = $data;
        foreach ($segments as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) return $default;
            $cur = $cur[$seg];
        }
        return $cur;
    }

    /** @param array<string,mixed> $data */
    public static function set(array &$data, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $cur =& $data;
        foreach ($segments as $i => $seg) {
            if ($i === count($segments) - 1) {
                $cur[$seg] = $value;
                return;
            }
            if (!isset($cur[$seg]) || !is_array($cur[$seg])) {
                $cur[$seg] = [];
            }
            $cur =& $cur[$seg];
        }
    }

    /**
     * Convert a flat dotted-key array into a nested array.
     *
     * @param array<string,mixed> $flat
     * @return array<string,mixed>
     */
    public static function undot(array $flat): array
    {
        $out = [];
        foreach ($flat as $k => $v) {
            if (!is_string($k) || $k === '') continue;
            self::set($out, $k, $v);
        }
        return $out;
    }
}
