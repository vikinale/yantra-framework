<?php
declare(strict_types=1);

namespace System\Cli;

final class Style
{
    public static function supportsColors(): bool
    {
        // Basic heuristics; safe fallback if unknown.
        if (PHP_SAPI !== 'cli') return false;

        if (getenv('NO_COLOR') !== false) return false;
        if (getenv('TERM') === 'dumb') return false;

        // Windows terminals vary; modern terminals support ANSI.
        return true;
    }

    public static function wrap(string $text, string $code): string
    {
        if (!self::supportsColors()) return $text;
        return "\033[" . $code . "m" . $text . "\033[0m";
    }

    public static function info(string $t): string { return self::wrap($t, '36'); }   // cyan
    public static function ok(string $t): string   { return self::wrap($t, '32'); }   // green
    public static function warn(string $t): string { return self::wrap($t, '33'); }   // yellow
    public static function err(string $t): string  { return self::wrap($t, '31'); }   // red
    public static function bold(string $t): string { return self::wrap($t, '1'); }    // bold
}
