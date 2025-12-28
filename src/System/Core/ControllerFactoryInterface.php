<?php
declare(strict_types=1);

namespace System\Core;

use System\Http\Request;
use System\Http\Response;

interface ControllerFactoryInterface
{
    public function make(string $controllerClass, Request $request, Response $response): object;
}
