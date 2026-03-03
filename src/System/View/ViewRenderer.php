<?php
declare(strict_types=1);

namespace System\View;

use RuntimeException;

final class ViewRenderer
{
    /** @var string[] */
    private array $paths = [];

    private string $ext;
    private bool $autoEscape = false;

    /** @var array<string, string> */
    private array $sections = [];
    private ?string $currentSection = null;
    private ?string $layout = null;

    public function __construct(array $paths, string $ext = '.php', bool $autoEscape = false)
    {
        $this->ext = $this->normalizeExt($ext);
        $this->autoEscape = $autoEscape;

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
        $this->layout = $layout;
        $this->sections = [];
        $this->currentSection = null;

        $viewFile = $this->resolve($view);
        $content  = $this->evaluate($viewFile, $data);

        if ($this->layout !== null) {
            $layoutFile = $this->resolve($this->layout);
            $this->sections['content'] = $content;
            $content = $this->evaluate($layoutFile, array_merge($data, [
                '_sections' => $this->sections,
                'content' => $content
            ]));
        }

        return $content;
    }



    /**
     * Render a specific absolute file path (useful for ThemeManager fallback strategies).
     */
    public function renderFile(string $file, array $data = [], ?string $layout = null): string
    {
        // If file ends in .blade.php, we might want to verify if it's in a registered path
        // because BladeOne usually requires views to be in registered paths to compile correctly (for relative includes).
        // However, for pure absolute path rendering, native PHP evaluate is safest unless we want to hack BladeOne.
        // Let's stick to native evaluate for absolute path unless we detect blade extension AND it's inside a view path?
        
        $file = rtrim($file);
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            throw new RuntimeException("View file not found/readable: {$file}");
        }
        
        // Simple check: if explicit file requested is .blade.php, try to render it?
        // But BladeOne needs "view name" not "file path".
        // So for renderFile, we stay with PHP native behavior for now to avoid complexity. 
        // Or strictly support .php only for renderFile.
        
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

    public function partial(string $view, array $data = []): string
    {
        try {
            $viewFile = $this->resolve($view);
            return $this->evaluate($viewFile, $data);
        } catch (\Throwable) {
            return '';
        }
    }

    public function section(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    public function endSection(): void
    {
        if ($this->currentSection !== null) {
            $this->sections[$this->currentSection] = (string) ob_get_clean();
            $this->currentSection = null;
        }
    }

    public function yield(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function setLayout(string $layout): void
    {
        $this->layout = $layout;
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
        
        // Handle Namespace::view
        if (str_contains($view, '::')) {
            [$ns, $file] = explode('::', $view, 2);
            if (!isset($this->namespaces[$ns])) {
                 throw new RuntimeException("View namespace not defined: {$ns}");
            }
            // Use the namespace path as the base
            $base = $this->namespaces[$ns];
            $file = str_replace(['..', '\\'], ['', '/'], $file);
            $file = str_replace('.', '/', $file);
            $relative = $file . $this->ext;
            $candidate = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
             throw new RuntimeException("View not found in namespace {$ns}: {$file}");
        }

        $view = str_replace(['..', '\\', '/'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], $view);
        // If it's dot notation, convert to separator for file resolution
        $view = str_replace('.', DIRECTORY_SEPARATOR, $view);
        $view = ltrim($view, DIRECTORY_SEPARATOR);

        if ($view === '') {
            throw new RuntimeException('Empty view name.');
        }

        $relative = $view . $this->ext;

        foreach ($this->paths as $base) {
            $candidate = $base . DIRECTORY_SEPARATOR . $relative;
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

        // Auto-escape if enabled
        if ($this->autoEscape) {
            $safeData = [];
            foreach ($data as $key => $value) {
                $safeData[$key] = new EscapedData($value);
            }
            $data = $safeData;
        }

        // Ensure we don't overwrite $view renderer if it's in data
        unset($data['view']);

        ob_start();
        try {
            extract($data, EXTR_OVERWRITE);
            include $file;
            $out = ob_get_clean();
            return $out === false ? '' : (string) $out;
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /** @var array<string, string> */
    private array $namespaces = [];

    public function addNamespace(string $namespace, string $path): void
    {
        $path = rtrim($path, '/\\');
        if ($path === '' || !is_dir($path)) {
            throw new RuntimeException("Namespace path not found: {$path}");
        }
        $this->namespaces[$namespace] = $path;
    }

    private function normalizeExt(string $ext): string
    {
        $ext = trim($ext);
        if ($ext === '') return '.php';
        return $ext[0] === '.' ? $ext : ('.' . $ext);
    }
}
