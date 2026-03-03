<?php
declare(strict_types=1);

namespace System\Testing\Sandbox;

class FsSandbox
{
    private string $root;

    public function __construct()
    {
        // Use a consistent prefix but unique per instance (per test case)
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yantra_test_' . uniqid('', true);
    }

    public function init(): void
    {
        if (!is_dir($this->root)) {
            mkdir($this->root, 0777, true);
        }
    }

    public function path(string $relative = ''): string
    {
        return $this->root . ($relative ? DIRECTORY_SEPARATOR . ltrim($relative, '\\/') : '');
    }

    public function cleanup(): void
    {
        if (is_dir($this->root)) {
            $this->deleteRecursive($this->root);
        }
    }

    private function deleteRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
