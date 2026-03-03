<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\CommandRegistry;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

final class ListCommand extends AbstractCommand
{
    public function __construct(private CommandRegistry $registry) {}

    public function name(): string { return 'list'; }
    public function description(): string { return 'List available commands.'; }

    public function run(Input $in, Output $out): int
    {
        $out->writeln(Style::bold("Commands:"));
        foreach ($this->registry->all() as $name => $cmd) {
            $out->writeln("  " . Style::info($name) . "  " . $cmd->description());
        }
        return 0;
    }
}
