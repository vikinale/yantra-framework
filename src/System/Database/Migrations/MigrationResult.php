<?php
declare(strict_types=1);

namespace System\Database\Migrations;

final class MigrationResult
{
    /**
     * @param string[] $ranMigrations
     */
    public function __construct(
        public readonly int $ranCount,
        public readonly int $batch,
        public readonly array $ranMigrations
    ) {}
}
