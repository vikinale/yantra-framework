<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Date implements RuleInterface
{
    public function __construct(private string $format = 'Y-m-d') {}
    public function passes(string $field, mixed $value, array $data): bool {
        if (!is_string($value)) return false;
        $d = \DateTime::createFromFormat($this->format, $value);
        return $d && $d->format($this->format) === $value;
    }
    public function message(string $field): string { return "{$field} does not match the format {$this->format}."; }
}
