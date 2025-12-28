<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;
use RuntimeException;

final class RoutesClearCommand extends AbstractCommand
{
    public function name(): string
    {
        return 'routes:clear';
    }

    public function description(): string
    {
        return 'Remove all compiled route cache files.';
    }

    public function usage(): array
    {
        return [
            'yantra routes:clear',
            'yantra routes:clear --cache=storage/cache/routes',
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $cache = (string)($in->option('cache', 'storage/cache/routes'));
        $base  = getcwd() ?: '.';

        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . trim($cache, '/\\');

        if (!is_dir($dir)) {
            $out->writeln(Style::warn("Route cache directory not found: {$dir}"));
            return 0;
        }

        $removed = 0;

        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') continue;

            if (
                $file === 'GET.php' ||
                $file === 'POST.php' ||
                $file === '__index.php' ||
                $file === '__errors.php' ||
                str_ends_with($file, '.tmp')
            ) {
                @unlink($dir . DIRECTORY_SEPARATOR . $file);
                $removed++;
            }
        }

        $out->writeln(
            $removed > 0
                ? Style::ok("Route cache cleared ({$removed} files).")
                : Style::warn("No route cache files found.")
        );

        return 0;
    }
}
