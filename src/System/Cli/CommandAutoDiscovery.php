<?php
declare(strict_types=1);

namespace System\Cli;

use ReflectionClass;

final class CommandAutoDiscovery
{
    public static function registerAll(CommandRegistry $registry, string $dir, string $nsPrefix): void
    {
        $debug = getenv('YANTRA_CLI_DEBUG') === '1';

        $dir = rtrim($dir, '/\\');
        if (!is_dir($dir)) {
            if ($debug) fwrite(STDERR, "[AutoDiscovery] Not a directory: {$dir}\n");
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*Command.php') ?: [];
        if ($debug) fwrite(STDERR, "[AutoDiscovery] Scanning: {$dir} (" . count($files) . " files)\n");

        foreach ($files as $file) {
            $class = basename($file, '.php');
            $fqcn  = $nsPrefix . $class;

            if ($debug) fwrite(STDERR, "\n[AutoDiscovery] File: {$file}\n[AutoDiscovery] FQCN: {$fqcn}\n");

            // IMPORTANT: if autoload mapping doesn't match, class_exists will always be false.
            // For debugging, attempt to load the file directly.
            if (!class_exists($fqcn)) {
                if ($debug) fwrite(STDERR, "[AutoDiscovery] class_exists=false; trying require_once...\n");
                require_once $file;
            }

            if (!class_exists($fqcn)) {
                if ($debug) fwrite(STDERR, "[AutoDiscovery] SKIP: class not found after require_once\n");
                continue;
            }

            $ref = new ReflectionClass($fqcn);

            if ($ref->isAbstract()) {
                if ($debug) fwrite(STDERR, "[AutoDiscovery] SKIP: abstract class\n");
                continue;
            }

            if (!$ref->implementsInterface(\System\Cli\CommandInterface::class)) {
                if ($debug) fwrite(STDERR, "[AutoDiscovery] SKIP: does not implement CommandInterface\n");
                continue;
            }

            $ctor = $ref->getConstructor();
            if ($ctor && $ctor->getNumberOfRequiredParameters() > 0) {
                if ($debug) fwrite(STDERR, "[AutoDiscovery] SKIP: constructor requires params\n");
                continue;
            }

            $registry->register($ref->newInstance());
            if ($debug) fwrite(STDERR, "[AutoDiscovery] REGISTERED: {$fqcn}\n");
        }
    }

}
