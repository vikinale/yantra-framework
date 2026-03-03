<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

final class MakeSeederCommand extends AbstractCommand
{
    public function name(): string { return 'make:seeder'; }

    public function description(): string { return 'Create a new database seeder.'; }

    public function usage(): array
    {
        return [
            "yantra make:seeder UserSeeder",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $name = $in->arg(0);
        if (!$name) {
            $out->error("Seeder name is required.");
            return 1;
        }

        $targetPath = (defined('BASEPATH') ? BASEPATH : getcwd()) . '/database/seeders/' . $name . '.php';

        $stub = <<<PHP
<?php
namespace Database\Seeders;

use PDO;

final class {$name}
{
    public function run(PDO \$pdo): void
    {
        // \$pdo->exec("INSERT INTO ...");
    }
}
PHP;

        $this->scaffoldFile($out, $targetPath, $stub);
        return 0;
    }
}
