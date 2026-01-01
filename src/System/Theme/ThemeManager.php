<?php
declare(strict_types=1);

namespace System\Theme;

use RuntimeException;
use System\Config;

final class ThemeManager
{
    private static ?self $instance = null;

    private bool $enabled = false;
    private ?string $activeSlug = null;

    private bool $booted = false;
    private ?Theme $active = null;

    private ThemeRegistry $registry;

    public function __construct()
    {
        $appCfg   = is_array(Config::get('app')) ? (array) Config::get('app') : [];
        $themeCfg = is_array($appCfg['theme'] ?? null) ? (array) $appCfg['theme'] : [];

        $this->enabled    = (bool)($themeCfg['enabled'] ?? false);
        $this->activeSlug = isset($themeCfg['active']) ? trim((string)$themeCfg['active']) : null;

        if (!defined('BASEPATH')) {
            throw new RuntimeException('BASEPATH is not defined; cannot resolve themes root.');
        }

        $themesRoot = rtrim((string)BASEPATH, '/\\') . DIRECTORY_SEPARATOR . 'themes';
        $this->registry = new ThemeRegistry($themesRoot);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) return;
        $this->booted = true;

        if (!$this->enabled) {
            return;
        }

        $this->registry->load();

        $slug = $this->activeSlug ? trim($this->activeSlug) : '';
        if ($slug === '') {
            throw new RuntimeException('Theme enabled but no active theme slug configured.');
        }

        if (!$this->registry->has($slug)) {
            throw new RuntimeException("Active theme slug not installed: {$slug}");
        }

        $this->active = $this->registry->get($slug);
    }

    /**
     * Resolve a view (or layout) name to an absolute theme file path.
     * No fallback to app views. If theme disabled => throws.
     */
    public function resolve(string $view): string
    {
        if (!$this->booted) {
            $this->boot();
        }

        if (!$this->enabled) {
            throw new RuntimeException('Theme system is disabled.');
        }

        if ($this->active === null) {
            throw new RuntimeException('Theme is enabled but no active theme is loaded.');
        }

        $paths = $this->themeViewPaths($this->active);
        $file  = $this->findViewFile($view, $paths);

        if ($file === null) {
            throw new RuntimeException("Theme view not found: {$view}");
        }

        return $file;
    }

    /**
     * Render a theme view and optional layout, both from theme chain.
     * Layout receives $content in $data['content'].
     */
    public function render(string $view, array $data = [], ?string $layout = null): string
    {
        $viewFile = $this->resolve($view);
        $content  = $this->evaluate($viewFile, $data);

        if ($layout !== null && $layout !== '') {
            $layoutFile = $this->resolve($layout);
            $content = $this->evaluate($layoutFile, $data + ['content' => $content]);
        }

        return $content;
    }

    /**
     * Evaluate a PHP template file in isolated scope.
     */
    private function evaluate(string $file, array $data): string
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException("Template not readable: {$file}");
        }

        // Isolated scope to avoid leaking $this etc into templates
        $renderer = function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        };

        try {
            return $renderer($file, $data);
        } catch (\Throwable $e) {
            // Wrap for consistent error reporting
            throw new RuntimeException("Error rendering template: {$file}", 0, $e);
        }
    }

    /** @return string[] */
    private function themeViewPaths(Theme $theme): array
    {
        $paths = [];
        $cur = $theme;

        while (true) {
            $paths[] = $cur->viewsPath(); // filesystem path to /views
            if ($cur->parent === null) break;
            $cur = $this->registry->get($cur->parent);
        }

        return $paths;
    }

    private function findViewFile(string $view, array $paths): ?string
    {
        // Normalize and prevent traversal
        $rel = str_replace(['..', '\\'], ['', '/'], $view);
        $rel = ltrim($rel, '/');
        if ($rel === '') {
            return null;
        }

        $rel .= '.php';

        foreach ($paths as $base) {
            $candidate = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
