<?php
declare(strict_types=1);

namespace System\Theme;

use RuntimeException;

final class ThemeRegistry
{
    /** @var array<string,Theme> */
    private array $themes = [];

    public function __construct(private string $themesRoot)
    {
        $this->themesRoot = rtrim($themesRoot, '/');
    }

    public function load(): void
    {
        if (!is_dir($this->themesRoot)) {
            throw new RuntimeException("Themes directory not found: {$this->themesRoot}");
        }

        foreach (scandir($this->themesRoot) ?: [] as $dir) {
            if ($dir === '.' || $dir === '..') continue;

            $path = $this->themesRoot . '/' . $dir;
            if (!is_dir($path)) continue;

            $manifest = $path . '/theme.json';
            $meta = [];
            $parent = null;

            if (is_file($manifest)) {
                $raw = file_get_contents($manifest);
                $meta = is_string($raw) ? json_decode($raw, true) : [];
                if (!is_array($meta)) $meta = [];
                $parent = isset($meta['parent']) ? (string)$meta['parent'] : null;
            }

            $name = (string)($meta['name'] ?? $dir);

            $this->themes[$name] = new Theme(
                name: $name,
                rootPath: $path,
                parent: $parent,
                meta: $meta
            );
        }
    }

    public function has(string $name): bool
    {
        return isset($this->themes[$name]);
    }

    public function get(string $name): Theme
    { 
        if (!$this->has($name)) {
            throw new RuntimeException("Theme not installed: {$name}");
        }
        return $this->themes[$name];
    }

    /** @return array<string,Theme> */
    public function all(): array
    {
        return $this->themes;
    }
}
