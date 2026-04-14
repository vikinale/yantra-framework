<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;
use System\Database\Database;

final class Exists implements RuleInterface
{
    public function __construct(private string $table, private string $column) {}

    public function passes(string $field, mixed $value, array $data): bool
    {
        if ($value === null || $value === '') return false;
        if (!$this->isSafeIdentifier($this->table) || !$this->isSafeIdentifier($this->column)) {
            return false;
        }

        $db = Database::getInstance();
        $sql = "SELECT COUNT(*) as count FROM `{$this->table}` WHERE `{$this->column}` = :value";
        $result = $db->fetch($sql, [':value' => $value]);
        return ($result['count'] ?? 0) > 0;
    }

    public function message(string $field): string { return "The selected {$field} is invalid."; }

    private function isSafeIdentifier(string $identifier): bool
    {
        return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier);
    }
}
