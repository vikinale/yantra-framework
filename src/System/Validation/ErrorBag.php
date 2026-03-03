<?php
declare(strict_types=1);

namespace System\Validation;

final class ErrorBag
{
    /** @var array<string, string[]> */
    private array $errors = [];

    public function add(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function has(string $field): bool
    {
        return isset($this->errors[$field]) && count($this->errors[$field]) > 0;
    }

    /** @return string[] */
    public function get(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    public function first(string $field): ?string
    {
        return ($this->errors[$field] ?? [])[0] ?? null;
    }

    /** @return array<string, string[]> */
    public function all(): array
    {
        return $this->errors;
    }

    /** @return array<string, string[]> */
    public function toArray(): array
    {
        return $this->errors;
    }

    public function isEmpty(): bool
    {
        return empty($this->errors);
    }
}
