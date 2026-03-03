<?php
declare(strict_types=1);

namespace System\Validation\Contracts;

interface RuleInterface
{
    /**
     * @param array<string,mixed> $data
     */
    public function passes(string $field, mixed $value, array $data): bool;

    public function message(string $field): string;
}
