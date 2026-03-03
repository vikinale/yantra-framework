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
        return rtrim($this->rootPath, '/\\');
    }


    public function manifestPath(): ?string
    {
        $m = $this->meta['assets']['manifest'] ?? null;
        return $m ? rtrim($this->rootPath, '/\\') . DIRECTORY_SEPARATOR . ltrim((string)$m, '/') : null;
    }
}
