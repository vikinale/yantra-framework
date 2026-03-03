<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

final class MakeModelCommand extends AbstractCommand
{
    public function name(): string { return 'make:model'; }

    public function description(): string { return 'Create a new database model.'; }

    public function usage(): array
    {
        return [
            "yantra make:model User",
            "yantra make:model Products/Category",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $name = $in->arg(0);
        if (!$name) {
            $out->error("Model name is required.");
            return 1;
        }

        $parts = explode('/', str_replace('\\', '/', $name));
        $className = array_pop($parts);
        $subNamespace = !empty($parts) ? '\\' . implode('\\', $parts) : '';
        $tableName = 'yt_' . strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className)) . 's';

        $targetPath = (defined('BASEPATH') ? BASEPATH : getcwd()) . '/App/Models/' . $name . '.php';

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\Models{$subNamespace};

use System\Database\Model;

final class {$className} extends Model
{
    protected string \$tableName = '{$tableName}';

    public function __construct()
    {
        parent::__construct();
    }
}
PHP;

        $this->scaffoldFile($out, $targetPath, $stub, (bool)$this->getOpt($in, 'force'));
        return 0;
    }
}
