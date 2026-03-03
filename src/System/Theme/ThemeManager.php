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
     * - Converts warnings/notices into exceptions (so you see exact template line).
     * - In debug mode, rethrows the original error so file/line are correct.
     */
    private function evaluate(string $file, array $data): string
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException("Template not readable: {$file}");
        }

        // Isolated scope to avoid leaking $this etc into templates
        $renderer = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();

            // Turn template warnings/notices into exceptions so they show up with exact file:line
            set_error_handler(static function (int $severity, string $message, string $errFile, int $errLine): bool {
                // Respect @ operator / suppressed errors
                if (!(error_reporting() & $severity)) {
                    return false;
                }
                throw new \ErrorException($message, 0, $severity, $errFile, $errLine);
            });

            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            } finally {
                restore_error_handler();
            }

            return (string) ob_get_clean();
        };

        try {
            return $renderer($file, $data);
        } catch (\Throwable $e) {
            // Log full detail for diagnostics
            error_log(
                "Theme render error: {$file}\n"
                . get_class($e) . ': ' . $e->getMessage()
                . " in {$e->getFile()}:{$e->getLine()}\n"
                . $e->getTraceAsString()
            );

            // In debug mode, rethrow original so the error page points to the real source line
            if ($this->isDebug()) {
                throw $e;
            }

            // Non-debug: keep consistent wrapper (but preserve original as previous)
            throw new RuntimeException("Error rendering template: {$file}", 0, $e);
        }
    }

    private function isDebug(): bool
    {
        // Constants (if your bootstrap defines them)
        if (!defined('APP_DEBUG')){
            define('APP_DEBUG',false);
        }
        else{
            return APP_DEBUG;
        }
        if(APP_DEBUG){return  true;}

        $appCfg = is_array(Config::get('app')) ? (array) Config::get('app') : [];

        if (array_key_exists('debug', $appCfg)) {
            return (bool) $appCfg['debug'];
        }

        $env = (string)($appCfg['env'] ?? $appCfg['environment'] ?? '');

        $env = strtolower(trim($env));
        return in_array($env, ['development', 'dev', 'local', 'testing'], true);
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

    public function hasView(string $view): bool
    {
        if (!$this->booted) {
            $this->boot();
        }

        if (!$this->enabled) {
            return false;
        }

        if ($this->active === null) {
            return false;
        }

        $paths = $this->themeViewPaths($this->active);
        return $this->findViewFile($view, $paths) !== null;
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
