<?php
declare(strict_types=1);

namespace System\Testing\Runner;

class Discovery
{
    public function findTests(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $classes = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $class = $this->resolveClassName($file->getPathname());
                if ($class && class_exists($class) && is_subclass_of($class, \System\Testing\Contracts\TestCase::class) && !(new \ReflectionClass($class))->isAbstract()) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);
        return $classes;
    }

    private function resolveClassName(string $path): ?string
    {
        $content = file_get_contents($path);
        
        if (!preg_match('/namespace\s+([^;]+);/', $content, $m)) {
            return null;
        }
        $namespace = $m[1];
        
        if (!preg_match('/class\s+([a-zA-Z0-9_]+)/', $content, $m)) {
            return null;
        }
        $class = $m[1];
        
        return $namespace . '\\' . $class;
    }
}
