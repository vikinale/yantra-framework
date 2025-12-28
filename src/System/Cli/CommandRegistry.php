<?php
declare(strict_types=1);

namespace System\Cli;

use System\Cli\Exceptions\CliException;

final class CommandRegistry
{
    /** @var array<string,CommandInterface> */
    private array $commands = [];

    public function register(CommandInterface $cmd): void
    {
        $name = trim($cmd->name());
        if ($name === '') {
            throw new CliException('Command name cannot be empty.');
        }
        $this->commands[$name] = $cmd;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    public function get(string $name): CommandInterface
    {
        if (!$this->has($name)) {
            throw new CliException("Unknown command: {$name}");
        }
        return $this->commands[$name];
    }

    /** @return array<string,CommandInterface> */
    public function all(): array
    {
        ksort($this->commands);
        return $this->commands;
    }
}
