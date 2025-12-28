<?php
declare(strict_types=1);

namespace System\Database\Migrations;

use PDO;

interface MigrationInterface
{
    public function up(PDO $pdo): void;

    public function down(PDO $pdo): void;
}
