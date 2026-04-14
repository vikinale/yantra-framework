<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;
use System\Database\Database;

final class Unique implements RuleInterface
{
    public function __construct(private string $table, private string $column, private ?string $ignoreId = null, private string $idColumn = 'id') {}

    public function passes(string $field, mixed $value, array $data): bool
    {
        if ($value === null || $value === '') return true;
        if (
            !$this->isSafeIdentifier($this->table) ||
            !$this->isSafeIdentifier($this->column) ||
            !$this->isSafeIdentifier($this->idColumn)
        ) {
            return false;
        }

        $db = Database::getInstance();
        $sql = "SELECT COUNT(*) as count FROM `{$this->table}` WHERE `{$this->column}` = :value";
        $params = [':value' => $value];
        if ($this->ignoreId !== null) {
            $sql .= " AND `{$this->idColumn}` != :ignore_id";
            $params[':ignore_id'] = $this->ignoreId;
        }
        $result = $db->fetch($sql, $params);
        return ($result['count'] ?? 0) == 0;
    }

    public function message(string $field): string { return "The {$field} has already been taken."; }

    private function isSafeIdentifier(string $identifier): bool
    {
        return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier);
    }
}
