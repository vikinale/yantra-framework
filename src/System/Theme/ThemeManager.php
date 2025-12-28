<?php
declare(strict_types=1);

namespace System\Theme;

use RuntimeException;
use System\View\ViewRenderer;

final class ThemeManager
{
    private ?Theme $active = null;
    private string $publicBaseUrl;
    private string $themesPublicPrefix;
    private string $publicAssetsPrefix;

    public function __construct(
        private ThemeRegistry $registry,
        private ViewRenderer $views,
        private bool $enabled = false,
        private bool $fallbackToViews = true,
        private ?string $activeSlug = null,
        string $publicBaseUrl = '',
        string $themesPublicPrefix = '/themes',
        string $publicAssetsPrefix = '/assets'
    ) {
        $this->publicBaseUrl = rtrim($publicBaseUrl, '/');
        $this->themesPublicPrefix = '/' . trim($themesPublicPrefix, '/');
        $this->publicAssetsPrefix = '/' . trim($publicAssetsPrefix, '/');
    }


    public function asset(string $path): string
    {
        $path = ltrim(trim($path), '/');

        // 1) Theme chain resolution (child -> parent)
        if ($this->enabled && $this->active !== null) {
            $ownerSlug = $this->findAssetOwnerSlug($this->active, $path);

            if ($ownerSlug !== null) {
                return $this->publicBaseUrl
                    . $this->themesPublicPrefix
                    . '/' . $ownerSlug
                    . '/assets/' . $path;
            }
        }

        // 2) Fallback to public assets
        return $this->publicBaseUrl
            . $this->publicAssetsPrefix
            . '/' . $path;
    }


    public function boot(): void
    {
        if (!$this->enabled) {
            return; // theme system inactive
        }

        $this->registry->load();

        $slug = $this->activeSlug ? trim($this->activeSlug) : '';
        if ($slug === '') {
            throw new RuntimeException("Theme enabled but no active theme slug configured.");
        }

        if (!$this->registry->has($slug)) {
            if ($this->fallbackToViews) {
                $this->active = null;
                return; // safe fallback
            }
            throw new RuntimeException("Active theme slug not installed: {$slug}");
        }

        $this->active = $this->registry->get($slug);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function hasActiveTheme(): bool
    {
        return $this->active !== null;
    }

    public function activeTheme(): ?Theme
    {
        return $this->active;
    }

    private function findAssetOwnerSlug(Theme $theme, string $assetPath): ?string
    {
        $cur = $theme;

        while (true) {
            $candidate = rtrim($cur->rootPath, '/\\') . DIRECTORY_SEPARATOR . 'assets'
                . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $assetPath);

            if (is_file($candidate) && is_readable($candidate)) {
                return $cur->name; // slug
            }

            if ($cur->parent === null) break;
            $cur = $this->registry->get($cur->parent);
        }

        return null;
    }
    /**
     * Render a view:
     * - If active theme exists: search child -> parent chain for view
     * - Else: render from app views
     */
    public function render(string $view, array $data = [], ?string $layout = null): string
    {
 
         $data['asset'] = $data['asset'] ?? function (string $path): string {
            return $this->asset($path);
        };
        
        if (!$this->enabled || $this->active === null) {
            return $this->views->render($view, $data, $layout);
        }

        $paths = $this->themeViewPaths($this->active); // child->parents
        // Preferred: ViewRenderer supports temporary override paths
        // If your ViewRenderer doesn't support it, you can add a method:
        // $this->views->withPaths($paths)->render(...)

        if (method_exists($this->views, 'withPaths')) {
            return $this->views->withPaths($paths)->render($view, $data, $layout);
        }

        // Fallback strategy if ViewRenderer can't switch paths:
        // try theme files manually, then call ViewRenderer with explicit file.
        $file = $this->findViewFile($view, $paths);
        if ($file !== null && method_exists($this->views, 'renderFile')) {
            return $this->views->renderFile($file, $data, $layout);
        }

        // Last resort fallback to app views
        if ($this->fallbackToViews) {
            return $this->views->render($view, $data, $layout);
        }

        throw new RuntimeException("Theme view not found and fallback disabled: {$view}");
    }

    /** @return string[] */
    private function themeViewPaths(Theme $theme): array
    {
        // Convention: views stored under /views within theme
        $paths = [];
        $cur = $theme;

        while (true) {
            $paths[] = rtrim($cur->rootPath, '/\\') . DIRECTORY_SEPARATOR . 'views';

            if ($cur->parent === null) break;
            $cur = $this->registry->get($cur->parent);
        }

        return $paths;
    }

    private function findViewFile(string $view, array $paths): ?string
    {
        $rel = str_replace(['..', '\\'], ['', '/'], $view);
        $rel = ltrim($rel, '/');
        $rel .= '.php';

        foreach ($paths as $base) {
            $candidate = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $rel;
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}


/*

declare(strict_types=1);

namespace System\Theme;

use System\Theme\Assets\AssetManager;
use System\Theme\View\PhpViewRenderer;
use System\Theme\View\ViewContext;
use RuntimeException;
use System\Config;
use System\Utilities\SessionStore;

final class ThemeManager
{
    private ?Theme $theme;
    public function __construct(
        private ThemeRegistry $registry, 
        private PhpViewRenderer $renderer,
        private AssetManager $assets
    ) {}

    public function activeTheme(): Theme
    {        
        if($this->theme??false)
            return $this->theme;

        $name = SessionStore::get('active_theme');
        if(empty($name)){
            $name = Config::get('app.theme.active')?? 'default';
            var_dump($name);
        }
        if (!$this->registry->has($name)) {
            // fallback to default if active theme missing
            $name = $this->registry->has('default') ? 'default' : $name;
        }
        $this->theme = $this->registry->get($name);
        return $this->theme;
    }
 
    public function findView(string $template): string
    {
        $template = ltrim($template, '/');
        $template = str_ends_with($template, '.php') ? $template : ($template . '.php');

        $theme = $this->activeTheme();

        while (true) {
            $candidate = $theme->viewsPath() . '/' . $template;
            if (is_file($candidate)) {
                return $candidate;
            }

            if (!$theme->parent) {
                break;
            }
            $theme = $this->registry->get($theme->parent);
        }

        throw new RuntimeException("View not found in theme chain: {$template}");
    }

    public function asset(string $path): string
    {
        return $this->assets->url($this->activeTheme(), $path);
    }

    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $ctx = new ViewContext($data);

        $body = $this->renderer->renderFile(
            $this->findView($template),
            $ctx
        );

        if ($layout === null) {
            return $body;
        }

        // Put body into $content slot; layout can echo $content
        return $this->renderer->renderFile(
            $this->findView($layout),
            $ctx,
            $body
        );
    }
}

*/