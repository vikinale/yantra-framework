<?php
declare(strict_types=1);

namespace System\Cli;

abstract class AbstractCommand implements CommandInterface
{
    public function usage(): array { return []; }

    protected function getOpt(Input $in, string $key): ?string
    {
        if (method_exists($in, 'option')) {
            $v = $in->option($key);
            return $v !== null ? (string) $v : null;
        }
        return $this->parseOpt((array) ($_SERVER['argv'] ?? []), $key);
    }

    protected function parseOpt(array $argv, string $key): ?string
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

    protected function scaffoldFile(Output $out, string $path, string $content, bool $force = false): void
    {
        if (is_file($path) && !$force) {
            $out->writeln(Style::warn("[SKIP] ") . $path . " (File already exists)");
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, $content);
        $out->writeln(Style::ok("[OK] ") . $path);
    }
}
