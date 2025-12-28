<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;
use RuntimeException;

final class EnvSetCommand extends AbstractCommand
{
    private const ALLOWED = ['development', 'production', 'staging'];

    public function name(): string
    {
        return 'env:set';
    }

    public function description(): string
    {
        return 'Set application environment (development|production|staging).';
    }

    public function usage(): array
    {
        return [
            'yantra env:set development',
            'yantra env:set production',
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $env = strtolower((string)$in->arg(0));

        if ($env === '' || !in_array($env, self::ALLOWED, true)) {
            throw new RuntimeException(
                'Invalid environment. Allowed: ' . implode(', ', self::ALLOWED)
            );
        }

        $file = getcwd() . DIRECTORY_SEPARATOR . '.env';

        $lines = [];
        if (is_file($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
        }

        $written = false;

        foreach ($lines as &$line) {
            if (str_starts_with($line, 'APP_ENV=')) {
                $line = "APP_ENV={$env}";
                $written = true;
            }
        }

        if (!$written) {
            $lines[] = "APP_ENV={$env}";
        }

        if (@file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL) === false) {
            throw new RuntimeException("Failed to write .env file.");
        }

        $out->writeln(Style::ok("Environment set to '{$env}'."));
        return 0;
    }
}
