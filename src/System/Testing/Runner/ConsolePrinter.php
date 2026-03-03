<?php
declare(strict_types=1);

namespace System\Testing\Runner;

class ConsolePrinter
{
    public function info(string $msg): void
    {
        echo $msg . PHP_EOL;
    }

    public function error(string $msg): void
    {
        echo "\033[31m" . $msg . "\033[0m" . PHP_EOL;
    }

    public function success(string $msg): void
    {
        echo "\033[32m" . $msg . "\033[0m" . PHP_EOL;
    }
    
    public function warning(string $msg): void
    {
        echo "\033[33m" . $msg . "\033[0m" . PHP_EOL;
    }
}
