<?php
declare(strict_types=1);

namespace System\Utilities;

interface SessionAdapterInterface
{
    public function start(): void;

    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function has(string $key): bool;
    public function remove(string $key): void;

    public function all(): array;
    public function clear(): void;

    public function regenerate(bool $deleteOldSession = true): void;
    public function destroy(): void;
}
