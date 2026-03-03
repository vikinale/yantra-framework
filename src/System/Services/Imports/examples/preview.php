<?php
declare(strict_types=1);

use System\Services\\Imports\Preview\Previewer;

$csv = __DIR__ . '/sample.csv';
$preview = (new Previewer())->preview($csv, 5);
print_r($preview);
