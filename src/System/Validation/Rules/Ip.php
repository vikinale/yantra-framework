<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Ip implements RuleInterface
{
    public function __construct(private bool $v4 = true, private bool $v6 = true) {}
    public function passes(string $field, mixed $value, array $data): bool {
        $flags = 0;
        if ($this->v4 && !$this->v6) $flags = FILTER_FLAG_IPV4;
        if ($this->v6 && !$this->v4) $flags = FILTER_FLAG_IPV6;
        return filter_var($value, FILTER_VALIDATE_IP, $flags) !== false;
    }
    public function message(string $field): string { return "{$field} must be a valid IP address."; }
}
