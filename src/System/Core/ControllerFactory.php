<?php
declare(strict_types=1);

namespace System\Core;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use System\Http\Request;
use System\Http\Response;

final class ControllerFactory implements ControllerFactoryInterface
{
    public function make(string $controllerClass, Request $request, Response $response): object
    {
        if (!class_exists($controllerClass)) {
            throw new RuntimeException("Controller class not found: {$controllerClass}");
        }

        $ref = new ReflectionClass($controllerClass);

        if (!$ref->isInstantiable()) {
            throw new RuntimeException("Controller not instantiable: {$controllerClass}");
        }

        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $ref->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $name = ltrim($type->getName(), '\\');

                if ($name === Request::class) {
                    $args[] = $request;
                    continue;
                }

                if ($name === Response::class) {
                    $args[] = $response;
                    continue;
                }
            }

            // If param has a default, use it.
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // If nullable, pass null
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            $pname = $param->getName();
            throw new RuntimeException(
                "Cannot resolve constructor parameter \${$pname} for controller {$controllerClass}. " .
                "Only Request/Response are auto-injected; other params must be optional or nullable."
            );
        }

        return $ref->newInstanceArgs($args);
    }
}
