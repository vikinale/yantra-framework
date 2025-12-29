<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use System\Database\Database;

final class DatabaseConnectionTest extends TestCase
{
    public function testPdoConnection(): void
    {
        putenv('DB_DRIVER=sqlite');
        putenv('DB_DATABASE=:memory:');

        $pdo = Database::pdo();

        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertEquals('sqlite', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }
}
