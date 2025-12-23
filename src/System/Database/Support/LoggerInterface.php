<?php
declare(strict_types=1);

namespace System\Database\Support;

interface LoggerInterface
{
    public function debug(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
}
