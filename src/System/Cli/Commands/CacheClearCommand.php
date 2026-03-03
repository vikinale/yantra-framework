<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

final class CacheClearCommand extends AbstractCommand
{
    public function name(): string { return 'cache:clear'; }

    public function description(): string { return 'Flush the application cache (routes, views, etc.)'; }

    public function run(Input $in, Output $out): int
    {
        $base = (defined('BASEPATH') ? BASEPATH : getcwd());
        $cachePaths = [
            $base . '/storage/cache/routes',
            $base . '/storage/cache/views',
        ];

        $cleared = 0;
        foreach ($cachePaths as $path) {
            if (is_dir($path)) {
                $this->removeDir($path);
                $cleared++;
                $out->writeln(Style::ok("[CLEARED] ") . $path);
            }
        }

        if ($cleared === 0) {
            $out->writeln("No cache directories found to clear.");
        } else {
            $out->writeln(Style::ok("Cache cleared successfully."));
        }

        return 0;
    }

    private function removeDir(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->removeDir("$dir/$file") : unlink("$dir/$file");
        }
        // Keep the base directory but empty
    }
}
