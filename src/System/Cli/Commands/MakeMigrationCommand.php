<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

final class MakeMigrationCommand extends AbstractCommand
{
    public function name(): string { return 'make:migration'; }

    public function description(): string { return 'Create a new migration file.'; }

    public function usage(): array
    {
        return [
            "yantra make:migration create_users_table",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $name = $in->arg(0);
        if (!$name) {
            $out->error("Migration name is required.");
            return 1;
        }

        $timestamp = date('Y_m_d_His');
        $fileName = $timestamp . '_' . $name . '.php';
        $targetPath = (defined('BASEPATH') ? BASEPATH : getcwd()) . '/database/migrations/' . $fileName;

        $stub = <<<PHP
<?php
declare(strict_types=1);

use System\Contracts\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO \$pdo): void
    {
        // \$pdo->exec("CREATE TABLE ...");
    }

    public function down(PDO \$pdo): void
    {
        // \$pdo->exec("DROP TABLE ...");
    }
};
PHP;

        $this->scaffoldFile($out, $targetPath, $stub);
        return 0;
    }
}
