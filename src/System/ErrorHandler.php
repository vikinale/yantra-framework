<?php
declare(strict_types=1);

namespace System;

final class ErrorHandler
{
    public static function handle(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        Logger::log("Error: [{$errno}] {$errstr} - {$errfile}:{$errline}");
        http_response_code(500);
        echo "Something went wrong!";
        return true; // prevents PHP internal handler
    }
}
