<?php
declare(strict_types=1);

namespace System\Theme\Assets;

use System\Theme\Theme;

final class AssetManager
{
    private array $manifestCache = [];

    public function __construct(
        private string $publicBase = '/themes'
    ) {}

    public function url(Theme $theme, string $path): string
    {
        $path = ltrim($path, '/');
        $final = $this->applyManifest($theme, $path);

        return rtrim($this->publicBase, '/')
            . '/'
            . rawurlencode($theme->name)
            . '/assets/'
            . ltrim($final, '/');
    }

    private function applyManifest(Theme $theme, string $path): string
    {
        $manifestPath = $theme->manifestPath();
        if (!$manifestPath || !is_file($manifestPath)) {
            return $path;
        }

        $key = $theme->name . '|' . $manifestPath;

        if (!isset($this->manifestCache[$key])) {
            $raw = file_get_contents($manifestPath);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            $this->manifestCache[$key] = is_array($data) ? $data : [];
        }

        // Example manifest: { "css/app.css": "css/app.9c1a3.css" }
        return (string)($this->manifestCache[$key][$path] ?? $path);
    }
}
