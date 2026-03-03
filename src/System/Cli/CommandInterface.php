<?php
declare(strict_types=1);

namespace System\Cli;

interface CommandInterface
{
    public function name(): string;
    public function description(): string;

    /**
     * Return usage examples, e.g.:
     *  ["yantra routes:cache", "yantra make:controller HomeController"]
     *
     * @return string[]
     */
    public function usage(): array;

    /**
     * Execute command.
     */
    public function run(Input $in, Output $out): int;
}
