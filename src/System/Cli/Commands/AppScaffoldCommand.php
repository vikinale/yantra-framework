<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

final class AppScaffoldCommand extends AbstractCommand
{
    public function name(): string { return 'app:scaffold'; }

    public function description(): string
    {
        return 'Generate a ready-to-start application with sample code (portfolio starter).';
    }

    public function usage(): array
    {
        return [
            "yantra app:scaffold",
            "yantra app:scaffold --force",
            "yantra app:scaffold --target=.",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $target = $this->opt($in, 'target') ?? (defined('BASEPATH') ? BASEPATH : getcwd());
        $force  = $this->hasFlag($in, '--force');

        $out->writeln(Style::bold("Scaffolding application"));
        $out->writeln("Target: {$target}");
        $out->writeln($force ? Style::warn("Force overwrite enabled") : "Force overwrite disabled");

        $manifest = $this->scaffoldRoot() . '/manifest.php';
        if (!is_file($manifest)) {
            $out->error(Style::err("Scaffold manifest not found"));
            return 1;
        }

        $map = require $manifest;
        $created = $skipped = 0;

        foreach ($map as $stubRel => $destRel) {
            $stub = $this->scaffoldRoot() . '/' . $stubRel;
            $dest = rtrim($target, '/\\') . '/' . str_replace(
                '{{timestamp}}',
                date('Y_m_d_His'),
                $destRel
            );

            if (!is_file($stub)) {
                $out->error(Style::err("Missing stub: {$stubRel}"));
                return 1;
            }

            if (is_file($dest) && !$force) {
                $out->writeln(Style::warn("[SKIP] ") . $dest);
                $skipped++;
                continue;
            }

            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0775, true);
            }

            file_put_contents($dest, file_get_contents($stub));
            $out->writeln(Style::ok("[OK] ") . $dest);
            $created++;
        }

        $out->writeln();
        $out->writeln(Style::ok("Scaffold complete") . " Created={$created}, Skipped={$skipped}");
        $out->writeln("Next steps:");
        $out->writeln("  composer dump-autoload");
        $out->writeln("  php vendor/bin/yantra migrate");
        $out->writeln("  php vendor/bin/yantra db:seed");
        $out->writeln("  php vendor/bin/yantra db:check");

        return 0;
    }

    private function scaffoldRoot(): string
    {
        return BASEPATH . '/vendor/yantra/framework/src/System/Cli/Scaffold/portfolio';
    }

    private function opt(Input $in, string $key): ?string
    {
        if (method_exists($in, 'option')) {
            return $in->option($key);
        }
        foreach ($_SERVER['argv'] ?? [] as $i => $a) {
            if ($a === "--{$key}" && isset($_SERVER['argv'][$i + 1])) {
                return $_SERVER['argv'][$i + 1];
            }
            if (str_starts_with($a, "--{$key}=")) {
                return substr($a, strlen("--{$key}="));
            }
        }
        return null;
    }

    private function hasFlag(Input $in, string $flag): bool
    {
        foreach ($_SERVER['argv'] ?? [] as $a) {
            if ($a === $flag) return true;
        }
        return false;
    }
}
