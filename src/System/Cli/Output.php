<?php
declare(strict_types=1);

namespace System\Cli;

final class Output
{
    public function write(string $msg): void
    {
        fwrite(STDOUT, $msg);
    }

    public function writeln(string $msg = ''): void
    {
        $this->write($msg . PHP_EOL);
    }

    public function error(string $msg): void
    {
        fwrite(STDERR, $msg . PHP_EOL);
    }
}
