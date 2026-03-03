<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

final class MakeServiceCommand extends AbstractCommand
{
    public function name(): string { return 'make:service'; }

    public function description(): string { return 'Create a new business service.'; }

    public function usage(): array
    {
        return [
            "yantra make:service AuthService",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $name = $in->arg(0);
        if (!$name) {
            $out->error("Service name is required.");
            return 1;
        }

        $parts = explode('/', str_replace('\\', '/', $name));
        $className = array_pop($parts);
        $subNamespace = !empty($parts) ? '\\' . implode('\\', $parts) : '';

        $targetPath = (defined('BASEPATH') ? BASEPATH : getcwd()) . '/App/Services/' . $name . '.php';

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\Services{$subNamespace};

use App\Services\BaseService;

final class {$className} extends BaseService
{
    public function __construct()
    {
        // Inject repositories here
    }
}
PHP;

        $this->scaffoldFile($out, $targetPath, $stub, (bool)$this->getOpt($in, 'force'));
        return 0;
    }
}
