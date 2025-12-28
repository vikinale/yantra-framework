<?php
declare(strict_types=1);

namespace System\Cli;

use ReflectionClass;

final class CommandAutoDiscovery
{
    public static function registerAll(CommandRegistry $registry, string $dir, string $nsPrefix): void
    {
        $dir = rtrim($dir, '/\\');
        if (!is_dir($dir)) return;

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*Command.php') ?: [] as $file) {
            $class = basename($file, '.php');
            $fqcn  = $nsPrefix . $class;

            if (!class_exists($fqcn)) continue;

            $ref = new ReflectionClass($fqcn);
            if ($ref->isAbstract()) continue;

            if (!$ref->implementsInterface(\System\Cli\CommandInterface::class)) continue;

            // Only supports zero-arg constructors
            $ctor = $ref->getConstructor();
            if ($ctor && $ctor->getNumberOfRequiredParameters() > 0) continue;

            $registry->register($ref->newInstance());
        }
    }
}
