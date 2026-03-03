<?php
declare(strict_types=1);

namespace System\Testing\Fixtures;

abstract class Seeders
{
    abstract public function run(): void;

    protected function db()
    {
        return \System\Database\Database::getInstance();
    }
}
