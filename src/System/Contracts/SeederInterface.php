<?php
declare(strict_types=1);

namespace System\Contracts;

use System\Database\Database;

interface SeederInterface
{
    public function run(Database $pdo): void;
}
