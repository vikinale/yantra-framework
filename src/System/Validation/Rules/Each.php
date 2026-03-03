<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;
use System\Validation\Validator;

final class Each implements RuleInterface
{
    public function __construct(private string|array $rules) {}

    public function passes(string $field, mixed $value, array $data): bool
    {
        if (!is_array($value)) return false;
        foreach ($value as $item) {
            $v = Validator::make(['item' => $item], ['item' => $this->rules]);
            if ($v->fails()) return false;
        }
        return true;
    }
    public function message(string $field): string { return "Format of items in {$field} is invalid."; }
}
