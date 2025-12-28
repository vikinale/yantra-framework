<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;
use System\Config;
use System\Database\Database;
use System\Database\Seeders\SeederRunner;

final class DbSeedCommand extends AbstractCommand
{
    public function name(): string { return 'db:seed'; }

    public function description(): string
    {
        return 'Run application seeders (default data).';
    }

    public function usage(): array
    {
        return [
            "yantra db:seed",
            "yantra db:seed --class=Database\\\\Seeders\\\\DatabaseSeeder",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $cfg = (array) Config::get('database');

        $seederClass = $this->getOpt($in, 'class')
            ?? ($cfg['database_seeder'] ?? 'Database\\Seeders\\DatabaseSeeder');

        try {
            $runner = new SeederRunner(Database::pdo());
            $runner->run((string) $seederClass);

            $out->writeln(Style::ok("Seeding complete: {$seederClass}"));
            return 0;
        } catch (\Throwable $e) {
            $out->error(Style::err("Seeding failed: " . $e->getMessage()));
            return 1;
        }
    }

    private function getOpt(Input $in, string $key): ?string
    {
        if (method_exists($in, 'option')) {
            $v = $in->option($key);
            return $v !== null ? (string) $v : null;
        }
        if (method_exists($in, 'args')) {
            return $this->parseOpt((array) $in->args(), $key);
        }
        return $this->parseOpt((array) ($_SERVER['argv'] ?? []), $key);
    }

    private function parseOpt(array $argv, string $key): ?string
    {
        $flagEq = '--' . $key . '=';
        $flag   = '--' . $key;

        foreach ($argv as $i => $a) {
            $a = (string) $a;

            if (str_starts_with($a, $flagEq)) {
                $val = substr($a, strlen($flagEq));
                return $val !== '' ? $val : null;
            }

            if ($a === $flag && isset($argv[$i + 1])) {
                $val = (string) $argv[$i + 1];
                return $val !== '' ? $val : null;
            }
        }

        return null;
    }
}
