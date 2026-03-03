<?php
declare(strict_types=1);

namespace System\Database\Support;

use System\Contracts\LoggerInterface;

final class NullLogger implements LoggerInterface
{
    public function debug(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {}
}
