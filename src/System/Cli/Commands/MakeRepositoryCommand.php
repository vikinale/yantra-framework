<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

final class MakeRepositoryCommand extends AbstractCommand
{
    public function name(): string { return 'make:repository'; }

    public function description(): string { return 'Create a new repository.'; }

    public function usage(): array
    {
        return [
            "yantra make:repository UserRepository",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $name = $in->arg(0);
        if (!$name) {
            $out->error("Repository name is required.");
            return 1;
        }

        $parts = explode('/', str_replace('\\', '/', $name));
        $className = array_pop($parts);
        $subNamespace = !empty($parts) ? '\\' . implode('\\', $parts) : '';
        $modelName = str_replace('Repository', '', $className);

        $targetPath = (defined('BASEPATH') ? BASEPATH : getcwd()) . '/App/Repositories/' . $name . '.php';

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\Repositories{$subNamespace};

use App\Repositories\BaseRepository;
use App\Models\Solar\\{$modelName};

final class {$className} extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new {$modelName}());
    }
}
PHP;

        $this->scaffoldFile($out, $targetPath, $stub, (bool)$this->getOpt($in, 'force'));
        return 0;
    }
}
