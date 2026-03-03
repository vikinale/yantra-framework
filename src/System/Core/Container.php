<?php
declare(strict_types=1);

namespace System\Core;

use System\Contracts\ContainerInterface;
use ReflectionClass;
use RuntimeException;

class Container implements ContainerInterface
{
    private array $definitions = [];
    private array $instances = [];

    /**
     * Set a shared instance.
     */
    public function set(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    /**
     * Load definitions from file or array.
     */
    public function addDefinitions(string|array $definitions): void
    {
        if (is_string($definitions) && file_exists($definitions)) {
            $defs = require $definitions;
            if (is_array($defs)) {
                $this->definitions = array_merge($this->definitions, $defs);
            }
        } elseif (is_array($definitions)) {
            $this->definitions = array_merge($this->definitions, $definitions);
        }
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (array_key_exists($id, $this->definitions)) {
            $definition = $this->definitions[$id];
            
            if (is_callable($definition)) {
                $instance = $definition($this);
                $this->instances[$id] = $instance;
                return $instance;
            }
            
            if (is_object($definition)) {
                $this->instances[$id] = $definition;
                return $definition;
            }

            if (is_string($definition) && class_exists($definition)) {
                 return $this->build($definition);
            }

            return $definition;
        }

        return $this->build($id);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) 
            || array_key_exists($id, $this->definitions) 
            || class_exists($id);
    }

    /**
     * Dynamically build a class using reflection.
     */
    public function build(string $className): mixed
    {
        if (!class_exists($className)) {
            throw new RuntimeException("DI Error: Class '{$className}' not found and no definition provided.");
        }

        $reflector = new ReflectionClass($className);
        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("DI Error: Class '{$className}' is not instantiable.");
        }

        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            $instance = new $className();
            $this->instances[$className] = $instance;
            return $instance;
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $dependencyClass = $type->getName();
                $dependencies[] = $this->get($dependencyClass);
            } else {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    $paramName = $parameter->getName();
                    throw new RuntimeException("DI Error: Cannot resolve un-typed parameter '\${$paramName}' in class '{$className}'.");
                }
            }
        }

        $instance = $reflector->newInstanceArgs($dependencies);
        $this->instances[$className] = $instance;
        return $instance;
    }
}
