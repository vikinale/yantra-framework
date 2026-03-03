<?php
declare(strict_types=1);

namespace System\Services\Reporting\Params;

/**
 * Param types supported by the Reporting Service.
 *
 * NOTE: Yantra targets PHP >= 8.0, so we use constants rather than PHP 8.1 enums.
 */
final class ParamType
{
    public const STRING = 'string';
    public const INT = 'int';
    public const FLOAT = 'float';
    public const BOOL = 'bool';
    public const DATE = 'date';          // DateTimeImmutable at 00:00:00 in context timezone
    public const DATETIME = 'datetime';  // DateTimeImmutable in context timezone
    public const ENUM = 'enum';
    public const ARRAY = 'array';        // array of scalars or arrays
    public const JSON = 'json';          // array|null decoded from JSON, or passed through if already array

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::STRING,
            self::INT,
            self::FLOAT,
            self::BOOL,
            self::DATE,
            self::DATETIME,
            self::ENUM,
            self::ARRAY,
            self::JSON,
        ];
    }
}
