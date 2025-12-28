<?php
declare(strict_types=1);

namespace System\Theme;

use RuntimeException;

final class ThemeRegistry
{
    /** @var array<string,Theme> slug => Theme */
    private array $themes = [];

    public function __construct(private string $themesRoot)
    {
        $this->themesRoot = rtrim($themesRoot, '/\\');
    }

    public function load(): void
    {
        if (!is_dir($this->themesRoot)) {
            throw new RuntimeException("Themes directory not found: {$this->themesRoot}");
        }

        // 1) scan + register (no parent validation yet)
        foreach (scandir($this->themesRoot) ?: [] as $dir) {
            if ($dir === '.' || $dir === '..') continue;

            $path = $this->themesRoot . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($path)) continue;

            $slug = $this->sanitizeSlug($dir);
            if ($slug === '') continue;

            $manifest = $path . DIRECTORY_SEPARATOR . 'theme.json';
            $meta = [];
            $parentSlug = null;

            if (is_file($manifest) && is_readable($manifest)) {
                $raw = file_get_contents($manifest);
                $meta = is_string($raw) ? json_decode($raw, true) : [];
                if (!is_array($meta)) $meta = [];

                if (isset($meta['parent']) && is_string($meta['parent'])) {
                    $parentSlug = $this->sanitizeSlug($meta['parent']); // STRICT slug
                    if ($parentSlug === '') $parentSlug = null;
                }
            }

            $displayName = (string)($meta['name'] ?? $slug);

            // Canonical fields (display name is metadata only)
            $meta['slug'] = $slug;
            $meta['name'] = $displayName;
            $meta['parent'] = $parentSlug;

            if (isset($this->themes[$slug])) {
                throw new RuntimeException("Duplicate theme slug detected: {$slug}");
            }

            $this->themes[$slug] = new Theme(
                name: $slug,        // canonical identifier = slug
                rootPath: $path,
                parent: $parentSlug, // STRICT slug
                meta: $meta
            );
        }

        // 2) validate parent chains
        foreach ($this->themes as $slug => $theme) {
            if ($theme->parent === null) continue;

            if (!isset($this->themes[$theme->parent])) {
                throw new RuntimeException(
                    "Theme '{$slug}' declares missing parent slug '{$theme->parent}'."
                );
            }

            // Optional: detect cycles (classic -> child -> classic etc.)
            $this->assertNoCycle($slug);
        }
    }

    public function has(string $slug): bool
    {
        return isset($this->themes[$slug]);
    }

    public function get(string $slug): Theme
    {
        if (!isset($this->themes[$slug])) {
            throw new RuntimeException("Theme not installed: {$slug}");
        }
        return $this->themes[$slug];
    }

    /** @return array<string,Theme> */
    public function all(): array
    {
        return $this->themes;
    }

    private function assertNoCycle(string $startSlug): void
    {
        $seen = [];
        $cur = $startSlug;

        while (isset($this->themes[$cur]) && $this->themes[$cur]->parent !== null) {
            if (isset($seen[$cur])) {
                throw new RuntimeException("Theme parent cycle detected at '{$cur}'.");
            }
            $seen[$cur] = true;
            $cur = $this->themes[$cur]->parent;
        }
    }

    private function sanitizeSlug(string $value): string
    {
        $s = strtolower(trim($value));
        $s = preg_replace('/[^a-z0-9\-_]/', '-', $s) ?? '';
        $s = preg_replace('/-+/', '-', $s) ?? $s;
        return trim($s, '-');
    }
}
