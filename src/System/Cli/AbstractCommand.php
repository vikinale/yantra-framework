<?php
declare(strict_types=1);

namespace System\Cli;

abstract class AbstractCommand implements CommandInterface
{
    public function usage(): array { return []; }
}
