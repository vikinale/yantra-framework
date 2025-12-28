<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\CommandRegistry;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

final class HelpCommand extends AbstractCommand
{
    public function __construct(private CommandRegistry $registry, private string $appName = 'yantra') {}

    public function name(): string { return 'help'; }
    public function description(): string { return 'Show help for a command.'; }

    public function usage(): array
    {
        return [
            "{$this->appName} help routes:cache",
            "{$this->appName} help",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $target = $in->arg(0);

        if (!$target) {
            $out->writeln("Usage: {$this->appName} help <command>");
            $out->writeln("Try: {$this->appName} list");
            return 0;
        }

        if (!$this->registry->has($target)) {
            $out->error(Style::err("Unknown command: {$target}"));
            return 2;
        }

        $cmd = $this->registry->get($target);
        $out->writeln(Style::bold("Command: ") . $cmd->name());
        $out->writeln($cmd->description());

        $usage = $cmd->usage();
        if ($usage !== []) {
            $out->writeln();
            $out->writeln(Style::bold("Usage:"));
            foreach ($usage as $u) {
                $out->writeln("  {$u}");
            }
        }

        return 0;
    }
}
