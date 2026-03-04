<?php
declare(strict_types=1);

namespace System\Contracts;

use System\Database\Database;

interface MigrationInterface
{
    public function up(Database $db): void;

    public function down(Database $db): void;
}
