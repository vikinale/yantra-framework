<?php
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
        }
        if (!$this->registry->has($name)) {
            // fallback to default if active theme missing
            $name = $this->registry->has('default') ? 'default' : $name;
        }
        $this->theme = $this->registry->get($name);
        return $this->theme;
    }

    /**
     * Resolve view file using fallback chain: active -> parent -> parent...
     */
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
