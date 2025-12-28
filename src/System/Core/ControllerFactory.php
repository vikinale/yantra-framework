<?php
declare(strict_types=1);

namespace System\Core;

use RuntimeException;
use System\Http\Request;
use System\Http\Response;
use System\View\ViewRenderer;
use System\Theme\ThemeManager;

final class ControllerFactory implements ControllerFactoryInterface
{
    public function __construct(
        private ViewRenderer $views,
        private ?ThemeManager $theme = null
    ) {}

    public function make(string $controllerClass, Request $request, Response $response): object
    {
        $ref = new \ReflectionClass($controllerClass);
        $ctor = $ref->getConstructor();

        if (!$ctor) {
            return $ref->newInstance();
        }

        $argc = $ctor->getNumberOfParameters();

        return match (true) {
            $argc >= 3 => $ref->newInstance($request, $response, $this->theme), // if your BaseController expects (req,res,theme)
            default    => $ref->newInstance($request, $response),
        };
    }

 
}
