<?php
declare(strict_types=1);

namespace System\Cli;

use System\Cli\Exceptions\CliException;
use Throwable;

final class ConsoleApplication
{
    public function __construct(
        private CommandRegistry $registry,
        private string $appName = 'yantra'
    ) {}

    public function run(array $argv): int
    {
        $in  = new Input($argv);
        $out = new Output();

        // Default behavior: show help/list
        $cmdName = $in->command();
        if ($cmdName === null || $cmdName === '' || $in->hasOption('h') || $in->hasOption('help')) {
            return $this->renderHelp($out);
        }

        if (!$this->registry->has($cmdName)) {
            $out->error(Style::err("Unknown command: {$cmdName}"));
            $out->writeln("Run `{$this->appName} --help` to see available commands.");
            return 2;
        }

        try {
            $cmd = $this->registry->get($cmdName);
            return $cmd->run($in, $out);
        } catch (CliException $e) {
            $out->error(Style::err($e->getMessage()));
            return 2;
        } catch (Throwable $e) {
            // Don’t leak stack traces by default
            $out->error(Style::err('Unhandled error: ') . $e->getMessage());
            if ($in->hasOption('v') || $in->hasOption('verbose')) {
                $out->error($e->getTraceAsString());
            }
            return 1;
        }
    }

    public function renderHelp(Output $out): int
    {
        $out->writeln(Style::bold("Yantra CLI"));
        $out->writeln("Usage: {$this->appName} <command> [args] [--options]");
        $out->writeln();
        $out->writeln(Style::bold("Available commands:"));

        foreach ($this->registry->all() as $name => $cmd) {
            $out->writeln("  " . Style::info($name) . "  " . $cmd->description());
        }

        $out->writeln();
        $out->writeln("Options:");
        $out->writeln("  --help, -h     Show help");
        $out->writeln("  --verbose, -v  Verbose errors");
        $out->writeln();

        return 0;
    }
}
