<?php
declare(strict_types=1);

namespace System\Contracts;

use PDO;

interface SeederInterface
{
    public function run(PDO $pdo): void;
}
