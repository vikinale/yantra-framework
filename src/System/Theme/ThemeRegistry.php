<?php
declare(strict_types=1);

namespace System\Theme;

use RuntimeException;

final class ThemeRegistry
{
    private string $root;
    private bool $loaded = false;

    /** @var array<string, Theme> */
    private array $themes = [];

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\");
    }

    public function root(): string
    {
        return $this->root;
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    public function load(): void
    {
        if ($this->loaded) {
            return;
        }

        if (!is_dir($this->root) || !is_readable($this->root)) {
            throw new RuntimeException("Themes root is not readable: {$this->root}");
        }

        $entries = scandir($this->root);
        if ($entries === false) {
            throw new RuntimeException("Unable to read themes root: {$this->root}");
        }

        // Filter directories only; stable ordering
        $slugs = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;

            $path = $this->root . DIRECTORY_SEPARATOR . $entry;

            // Only direct directories; optionally skip symlinks
            if (!is_dir($path)) continue;
            if ($this->isDotPath($entry)) continue;

            $slugs[] = $entry;
        }
        sort($slugs, SORT_STRING);

        // Build themes map
        foreach ($slugs as $slug) {
            $themeRoot = $this->root . DIRECTORY_SEPARATOR . $slug;
            $manifest  = $themeRoot . DIRECTORY_SEPARATOR . 'theme.json';

            if (!is_file($manifest) || !is_readable($manifest)) {
                // Treat as "not installed" if no manifest (strict and predictable)
                continue;
            }

            $meta = $this->readJson($manifest);

            // If manifest defines slug/name, you can validate it matches folder.
            // For now, folder name is the canonical slug.
            $parent = isset($meta['parent']) && is_string($meta['parent']) && trim($meta['parent']) !== ''
                ? trim($meta['parent'])
                : null;

            $this->themes[$slug] = new Theme(
                name: $slug,
                rootPath: $themeRoot,
                parent: $parent
            );
        }

        // Validate parents exist + no cycles
        $this->validateParentLinks();
        $this->validateNoCycles();

        $this->loaded = true;
    }

    public function has(string $slug): bool
    {
        $slug = trim($slug);
        if ($slug === '') return false;

        $this->load();
        return isset($this->themes[$slug]);
    }

    public function get(string $slug): Theme
    {
        $slug = trim($slug);
        if ($slug === '') {
            throw new RuntimeException('Theme slug cannot be empty.');
        }

        $this->load();

        if (!isset($this->themes[$slug])) {
            throw new RuntimeException("Theme not installed: {$slug}");
        }

        return $this->themes[$slug];
    }

    /** @return array<string, Theme> */
    public function all(): array
    {
        $this->load();
        return $this->themes;
    }

    private function validateParentLinks(): void
    {
        foreach ($this->themes as $slug => $theme) {
            if ($theme->parent === null) continue;

            if (!isset($this->themes[$theme->parent])) {
                throw new RuntimeException("Theme '{$slug}' has missing parent theme '{$theme->parent}'.");
            }
        }
    }

    private function validateNoCycles(): void
    {
        // DFS cycle detection on parent pointers
        $visiting = [];
        $visited  = [];

        foreach ($this->themes as $slug => $_theme) {
            $this->dfsCheck($slug, $visiting, $visited);
        }
    }

    private function dfsCheck(string $slug, array &$visiting, array &$visited): void
    {
        if (isset($visited[$slug])) return;

        if (isset($visiting[$slug])) {
            throw new RuntimeException("Theme parent cycle detected at '{$slug}'.");
        }

        $visiting[$slug] = true;

        $theme = $this->themes[$slug];
        if ($theme->parent !== null) {
            $parent = $theme->parent;

            // parent existence already validated, but keep safe
            if (!isset($this->themes[$parent])) {
                throw new RuntimeException("Theme '{$slug}' has missing parent '{$parent}'.");
            }

            $this->dfsCheck($parent, $visiting, $visited);
        }

        unset($visiting[$slug]);
        $visited[$slug] = true;
    }

    private function readJson(string $file): array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException("Unable to read theme manifest: {$file}");
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON in theme manifest: {$file}");
        }

        return $data;
    }

    private function isDotPath(string $name): bool
    {
        // extra safety
        return $name === '' || $name[0] === '.';
    }
}
