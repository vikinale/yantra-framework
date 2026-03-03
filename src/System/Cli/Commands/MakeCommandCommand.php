<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

final class MakeCommandCommand extends AbstractCommand
{
    public function name(): string { return 'make:command'; }

    public function description(): string { return 'Create a new CLI command.'; }

    public function usage(): array
    {
        return [
            "yantra make:command CustomCommand",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $name = $in->arg(0);
        if (!$name) {
            $out->error("Command class name is required.");
            return 1;
        }

        $commandId = strtolower(preg_replace('/(?<!^)[A-Z]/', ':$0', str_replace('Command', '', $name)));
        
        // Default to src/System/Cli/Commands for framework-style or adjust for App
        $targetPath = (defined('BASEPATH') ? BASEPATH : getcwd()) . '/src/System/Cli/Commands/' . $name . '.php';

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

final class {$name} extends AbstractCommand
{
    public function name(): string { return '{$commandId}'; }

    public function description(): string { return 'Description for {$commandId}'; }

    public function run(Input \$in, Output \$out): int
    {
        \$out->writeln(Style::ok("Running {$commandId}"));
        return 0;
    }
}
PHP;

        $this->scaffoldFile($out, $targetPath, $stub);
        return 0;
    }
}
