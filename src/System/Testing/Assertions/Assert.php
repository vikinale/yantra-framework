<?php
declare(strict_types=1);

namespace System\Testing\Assertions;

use RuntimeException;
use PHPUnit\Framework\Assert as PHPUnitAssert;

class Assert
{
    public static function true(mixed $condition, string $msg = ''): void
    {
        PHPUnitAssert::assertTrue($condition, $msg);
    }

    public static function false(mixed $condition, string $msg = ''): void
    {
        PHPUnitAssert::assertFalse($condition, $msg);
    }

    public static function equals(mixed $expected, mixed $actual, string $msg = ''): void
    {
        PHPUnitAssert::assertEquals($expected, $actual, $msg);
    }
    
    public static function same(mixed $expected, mixed $actual, string $msg = ''): void
    {
        PHPUnitAssert::assertSame($expected, $actual, $msg);
    }

    public static function count(int $expectedCount, array|\Countable $haystack, string $msg = ''): void
    {
        PHPUnitAssert::assertCount($expectedCount, $haystack, $msg);
    }

    public static function null(mixed $value, string $msg = ''): void
    {
        PHPUnitAssert::assertNull($value, $msg);
    }

    public static function notNull(mixed $value, string $msg = ''): void
    {
        PHPUnitAssert::assertNotNull($value, $msg);
    }
}
