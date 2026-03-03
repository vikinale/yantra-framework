<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class MaxFileSize implements RuleInterface
{
    public function __construct(private int $maxBytes) {}
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (!is_array($value) || !isset($value['size'])) return false;
        return (int)$value['size'] <= $this->maxBytes;
    }
    public function message(string $field): string { return "File size exceeds limit."; }
}
