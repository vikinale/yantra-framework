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
        $this->ext = $ext;
        foreach ($paths as $p) {
            $this->addPath($p);
        }
    }

    public function addPath(string $path): void
    {
        $path = rtrim($path, '/\\');
        if ($path === '' || !is_dir($path)) {
            throw new RuntimeException("View path not found: {$path}");
        }
        $this->paths[] = $path;
    }

    public function prependPath(string $path): void
    {
        $path = rtrim($path, '/\\');
        if ($path === '' || !is_dir($path)) {
            throw new RuntimeException("View path not found: {$path}");
        }
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

    public function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException("View not found: {$view}");
    }

    private function evaluate(string $file, array $data): string
    {
        $view = $this; // expose as $view in templates (escape helper)
        ob_start();
        try {
            extract($data, EXTR_SKIP);
            require $file;
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
