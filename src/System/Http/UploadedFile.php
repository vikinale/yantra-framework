<?php
declare(strict_types=1);

namespace System\Http;

final class UploadedFile
{
    private bool $moved = false;

    public function __construct(private \Psr\Http\Message\UploadedFileInterface $psr) {}

    public function psr(): \Psr\Http\Message\UploadedFileInterface
    {
        return $this->psr;
    }

    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new \RuntimeException('Uploaded file already moved.');
        }
        $this->psr->moveTo($targetPath);
        $this->moved = true;
    }
}
