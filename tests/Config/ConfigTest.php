<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use System\Config;

final class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        Config::setAppPath(BASEPATH . '/tests/fixtures/app');
        Config::setConfigDir('Config');
    }

    public function testLoadsConfigFile(): void
    {
        $config = Config::get('app');

        $this->assertIsArray($config);
        error_log(json_encode($config));
        $this->assertArrayHasKey('environment', $config);
    }
}
