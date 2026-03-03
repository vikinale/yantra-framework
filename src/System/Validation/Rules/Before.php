<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Before implements RuleInterface
{
    public function __construct(private string $date) {}
    public function passes(string $field, mixed $value, array $data): bool {
        $other = isset($data[$this->date]) ? $data[$this->date] : $this->date;
        try {
            $dt1 = new \DateTime((string)$value);
            $dt2 = new \DateTime((string)$other);
            return $dt1 < $dt2;
        } catch(\Exception $e) { return false; }
    }
    public function message(string $field): string { return "{$field} must be a date before {$this->date}."; }
}
