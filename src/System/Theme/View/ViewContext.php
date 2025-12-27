<?php
declare(strict_types=1);

namespace System\Theme\View;

final class ViewContext
{
    public function __construct(private array $data = []) {}

    public function all(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function with(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->data[$key] = $value;
        return $clone;
    }
}
