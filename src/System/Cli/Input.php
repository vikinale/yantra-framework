<?php
declare(strict_types=1);

namespace System\Cli;

final class Input
{
    /** @var string[] */
    private array $argv;

    /** @var string[] */
    private array $args = [];

    /** @var array<string,mixed> */
    private array $opts = [];

    private ?string $command = null;

    public function __construct(array $argv)
    {
        $this->argv = array_values(array_map('strval', $argv));
        $this->parse();
    }

    public function command(): ?string
    {
        return $this->command;
    }

    /** @return string[] */
    public function args(): array
    {
        return $this->args;
    }

    public function arg(int $index, ?string $default = null): ?string
    {
        return $this->args[$index] ?? $default;
    }

    public function hasOption(string $key): bool
    {
        return array_key_exists($key, $this->opts);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->opts[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function options(): array
    {
        return $this->opts;
    }

    private function parse(): void
    {
        // argv[0] is script name
        $tokens = array_slice($this->argv, 1);

        // first non-option token is command (if any)
        if ($tokens !== [] && $tokens[0] !== '' && $tokens[0][0] !== '-') {
            $this->command = array_shift($tokens);
        }

        foreach ($tokens as $t) {
            $t = trim((string)$t);
            if ($t === '') continue;

            // long opt: --key=value or --flag
            if (str_starts_with($t, '--')) {
                $raw = substr($t, 2);
                if ($raw === '') continue;

                if (str_contains($raw, '=')) {
                    [$k, $v] = explode('=', $raw, 2);
                    $k = trim($k);
                    $this->opts[$k] = $v;
                } else {
                    $this->opts[$raw] = true;
                }
                continue;
            }

            // short opt: -v or -abc
            if (str_starts_with($t, '-')) {
                $raw = substr($t, 1);
                if ($raw === '') continue;

                // -k=value support
                if (str_contains($raw, '=')) {
                    [$k, $v] = explode('=', $raw, 2);
                    $this->opts[$k] = $v;
                } else {
                    foreach (str_split($raw) as $ch) {
                        $this->opts[$ch] = true;
                    }
                }
                continue;
            }

            // plain arg
            $this->args[] = $t;
        }
    }
}
