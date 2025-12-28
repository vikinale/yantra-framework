<?php
declare(strict_types=1);

namespace System\View;

use RuntimeException;

final class ViewRenderer
{
    /** @var string[] */
    private array $paths = [];

    private string $ext;

    public function __construct(array $paths, string $ext = '.php')
    {
        $this->ext = $this->normalizeExt($ext);

        foreach ($paths as $p) {
            $this->addPath((string) $p);
        }

        if ($this->paths === []) {
            throw new RuntimeException('ViewRenderer requires at least one valid view path.');
        }
    }

    /**
     * Create a cloned renderer with an entirely new path stack.
     * This enables theme rendering without mutating the shared renderer.
     */
    public function withPaths(array $paths): self
    {
        $clone = clone $this;
        $clone->paths = [];

        foreach ($paths as $p) {
            $clone->addPath((string) $p);
        }

        if ($clone->paths === []) {
            throw new RuntimeException('withPaths() requires at least one valid view path.');
        }

        return $clone;
    }

    /** @return string[] */
    public function getPaths(): array
    {
        return $this->paths;
    }

    public function addPath(string $path): void
    {
        $path = rtrim($path, '/\\');
        if ($path === '' || !is_dir($path)) {
            throw new RuntimeException("View path not found: {$path}");
        }

        // avoid duplicates
        if (!in_array($path, $this->paths, true)) {
            $this->paths[] = $path;
        }
    }

    public function prependPath(string $path): void
    {
        $path = rtrim($path, '/\\');
        if ($path === '' || !is_dir($path)) {
            throw new RuntimeException("View path not found: {$path}");
        }

        // avoid duplicates (ensure it is first)
        $this->paths = array_values(array_filter(
            $this->paths,
            static fn(string $p): bool => $p !== $path
        ));
        array_unshift($this->paths, $path);
    }

    public function render(string $view, array $data = [], ?string $layout = null): string
    {
        $viewFile = $this->resolve($view);
        $content  = $this->evaluate($viewFile, $data);

        if ($layout !== null) {
            $layoutFile = $this->resolve($layout);
            $content = $this->evaluate($layoutFile, $data + ['content' => $content]);
        }

        return $content;
    }

    /**
     * Render a specific absolute file path (useful for ThemeManager fallback strategies).
     */
    public function renderFile(string $file, array $data = [], ?string $layout = null): string
    {
        $file = rtrim($file);
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            throw new RuntimeException("View file not found/readable: {$file}");
        }

        $content = $this->evaluate($file, $data);

        if ($layout !== null) {
            $layoutFile = $this->resolve($layout);
            $content = $this->evaluate($layoutFile, $data + ['content' => $content]);
        }

        return $content;
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Check if a view exists in any configured path.
     */
    public function exists(string $view): bool
    {
        try {
            $this->resolve($view);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolve(string $view): string
    {
        $view = trim($view);
        $view = str_replace(['..', '\\'], ['', '/'], $view);
        $view = ltrim($view, '/');

        if ($view === '') {
            throw new RuntimeException('Empty view name.');
        }

        $relative = $view . $this->ext;

        foreach ($this->paths as $base) {
            $candidate = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException("View not found: {$view}");
    }

    private function evaluate(string $file, array $data): string
    {
        // Expose renderer as $view in templates (escape helper etc.)
        $view = $this;

        ob_start();
        try {
            extract($data, EXTR_SKIP);
            require $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    private function normalizeExt(string $ext): string
    {
        $ext = trim($ext);
        if ($ext === '') return '.php';
        return $ext[0] === '.' ? $ext : ('.' . $ext);
    }
}
