<?php
declare(strict_types=1);

namespace System\Testing\Sandbox;

use System\Contracts\SessionAdapterInterface;

class ArraySessionAdapter implements SessionAdapterInterface
{
    private array $storage = [];
    private bool $started = false;

    public function start(): void
    {
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->storage) ? $this->storage[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->storage[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->storage);
    }

    public function remove(string $key): void
    {
        unset($this->storage[$key]);
    }

    public function all(): array
    {
        return $this->storage;
    }

    public function clear(): void
    {
        $this->storage = [];
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        if ($deleteOldSession) {
            // mimic session regeneration
        }
    }

    public function destroy(): void
    {
        $this->storage = [];
        $this->started = false;
    }
}
