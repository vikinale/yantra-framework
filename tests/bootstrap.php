<?php
declare(strict_types=1);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__.'/error.log');

define('YANTRA_ENV', 'testing');
define('BASEPATH', dirname(__DIR__));

require BASEPATH . '/vendor/autoload.php';

if (class_exists(\System\Config::class)) {
    \System\Config::setAppPath(BASEPATH . '/tests/fixtures/app');
    \System\Config::setConfigDir('config');

    // If your Config caches, clear it (add this method if not present)
    if (method_exists(\System\Config::class, 'clear')) {
        \System\Config::clear();
    }
}
