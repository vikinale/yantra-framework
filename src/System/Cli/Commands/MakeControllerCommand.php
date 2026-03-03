<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

final class MakeControllerCommand extends AbstractCommand
{
    public function name(): string { return 'make:controller'; }

    public function description(): string { return 'Create a new controller.'; }

    public function usage(): array
    {
        return [
            "yantra make:controller UserController",
            "yantra make:controller Admin/DashboardController",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $name = $in->arg(0);
        if (!$name) {
            $out->error("Controller name is required.");
            return 1;
        }

        $parts = explode('/', str_replace('\\', '/', $name));
        $className = array_pop($parts);
        $subNamespace = !empty($parts) ? '\\' . implode('\\', $parts) : '';
        
        $targetPath = (defined('BASEPATH') ? BASEPATH : getcwd()) . '/App/Controllers/' . $name . '.php';
        
        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\Controllers{$subNamespace};

final class {$className} extends BaseController
{
    public function index(): void
    {
        // \$this->render('pages/index');
    }
}
PHP;

        $this->scaffoldFile($out, $targetPath, $stub, (bool)$this->getOpt($in, 'force'));
        return 0;
    }
}
