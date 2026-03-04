<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;
use System\Database\Database;
use Exception;

final class DbMakeModelCommand extends AbstractCommand
{
    public function name(): string { return 'db:make:model'; }

    public function description(): string 
    { 
        return 'Generate a Yantra Model by inspecting an existing database table.'; 
    }

    public function usage(): array
    {
        return [
            "yantra db:make:model yt_contacts",
            "yantra db:make:model yt_contacts --class=Contact",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $tableName = $in->arg(0);
        if (!$tableName) {
            $out->error("Table name is required.");
            return 1;
        }

        try {
            $db = Database::getInstance();
            $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);

            // 1. Check if table exists and get columns
            $columns = [];
            if ($driver === 'mysql') {
                $stmt = $db->execute("DESCRIBE `{$tableName}`");
                $rawColumns = $stmt->fetchAll();
                foreach ($rawColumns as $col) {
                    $columns[] = $col['Field'];
                }
            } elseif ($driver === 'sqlite') {
                $stmt = $db->execute("PRAGMA table_info(`{$tableName}`)");
                $rawColumns = $stmt->fetchAll();
                foreach ($rawColumns as $col) {
                    $columns[] = $col['name'];
                }
            } else {
                $out->error("Driver '{$driver}' is not supported for auto-generation yet.");
                return 1;
            }

            if (empty($columns)) {
                $out->error("Table '{$tableName}' not found or has no columns.");
                return 1;
            }

            // 2. Prepare Model metadata
            $customClass = $this->getOpt($in, 'class');
            $className = $customClass ?: $this->classNameFromTable($tableName);
            
            $fillable = [];
            foreach ($columns as $colName) {
                if ($colName && !in_array($colName, ['id', 'created_at', 'updated_at'])) {
                    $fillable[] = $colName;
                }
            }

            // 3. Generate content
            $targetPath = (defined('BASEPATH') ? BASEPATH : getcwd()) . '/App/Models/' . $className . '.php';
            $fillableStr = !empty($fillable) 
                ? "['" . implode("', '", $fillable) . "']" 
                : "[]";

            $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\Models;

use System\Database\Model;

final class {$className} extends Model
{
    protected string \$tableName = '{$tableName}';

    /** @var array<int,string> */
    protected array \$fillable = {$fillableStr};

    public function __construct()
    {
        parent::__construct();
    }
}
PHP;

            $this->scaffoldFile($out, $targetPath, $stub, (bool)$this->getOpt($in, 'force'));
            return 0;

        } catch (Exception $e) {
            $out->error("Database error: " . $e->getMessage());
            return 1;
        }
    }

    private function classNameFromTable(string $table): string
    {
        // Remove prefix (e.g. yt_)
        $clean = preg_replace('/^yt_/', '', $table);
        
        // Singularize (very basic: remove trailing 's')
        $singular = rtrim($clean, 's');
        
        // camelCase to PascalCase
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $singular)));
    }
}
