<?php
declare(strict_types=1);

namespace System\Theme;

final class Theme
{
    public function __construct(
        public readonly string $name,
        public readonly string $rootPath,
        public readonly ?string $parent = null,
        public readonly array $meta = [],
    ) {}

    public function viewsPath(): string
    {
        return $this->rootPath . '/views';
    }

    public function assetsPath(): string
    {
        return $this->rootPath . '/assets';
    }

    public function manifestPath(): ?string
    {
        $m = $this->meta['assets']['manifest'] ?? null;
        return $m ? $this->rootPath . '/' . ltrim((string)$m, '/') : null;
    }
}
