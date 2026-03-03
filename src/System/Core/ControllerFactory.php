<?php
declare(strict_types=1);

namespace System\Core;

use System\Contracts\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use System\Contracts\ControllerFactoryInterface;
use System\Core\Routing\ModelBindingResolver;
use System\Http\Request;
use System\Http\Response;

final class ControllerFactory implements ControllerFactoryInterface
{
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

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
            // Even if no constructor, use container to instantiate (might have property injection?)
            // But simple newInstance is faster if we know it has no dependencies.
            // For consistency, let's just use newInstance as before.
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

                // Resolve from container (PHP-DI handles autowiring)
                try {
                    $args[] = $this->container->get($name);
                    continue;
                } catch (\Throwable $e) {
                    // Fallthrough to default handling
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
                "Cannot resolve constructor parameter \${$pname} for controller {$controllerClass}."
            );
        }

        return $ref->newInstanceArgs($args);
    }
}
