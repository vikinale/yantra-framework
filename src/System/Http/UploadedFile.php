<?php
declare(strict_types=1);

namespace System\Http;

use RuntimeException;
use finfo;

final class UploadedFile
{
    private bool $moved = false;
    private ?string $movedPath = null;

    public function __construct(
        private string $tmpName,
        private int $size,
        private int $error,
        private string $clientFilename,
        private string $clientMediaType
    ) {}

    public function getClientFilename(): string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): string
    {
        return $this->clientMediaType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function movedPath(): ?string
    {
        return $this->movedPath;
    }

    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new RuntimeException('Uploaded file already moved.');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot move file with upload error: ' . $this->error);
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (is_uploaded_file($this->tmpName)) {
            $moved = move_uploaded_file($this->tmpName, $targetPath);
        } else {
            // Fallback for testing environments
            $moved = @rename($this->tmpName, $targetPath);
        }

        if (!$moved) {
            throw new RuntimeException(sprintf('Error moving uploaded file %s to %s', $this->tmpName, $targetPath));
        }

        $this->moved = true;
        $this->movedPath = $targetPath;
    }

    public function moveToUnique(string $targetPath, int $maxTries = 10_000): string
    {
        if ($this->moved) {
            throw new RuntimeException('Uploaded file already moved.');
        }

        $finalPath = self::resolveUniquePath($targetPath, $maxTries);
        $this->moveTo($finalPath);

        return $finalPath;
    }

    private static function resolveUniquePath(string $targetPath, int $maxTries): string
    {
        $dir  = dirname($targetPath);
        $base = basename($targetPath);

        $ext  = pathinfo($base, PATHINFO_EXTENSION);
        $name = pathinfo($base, PATHINFO_FILENAME);

        $extPart = ($ext !== '') ? ('.' . $ext) : '';

        $candidate = $dir . DIRECTORY_SEPARATOR . $name . $extPart;
        if (!file_exists($candidate)) {
            return $candidate;
        }

        for ($i = 1; $i <= $maxTries; $i++) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $name . '-' . $i . $extPart;
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to find a unique filename after '.$maxTries.' attempts.');
    }

    public function validate(array $allowedExtensions = [], int $maxBytes = 0): bool
    {
        if ($this->getError() !== UPLOAD_ERR_OK) {
             throw new RuntimeException('File upload failed with error code: ' . $this->getError());
        }

        if ($maxBytes > 0 && $this->getSize() > $maxBytes) {
            throw new RuntimeException("File too large. Max: {$maxBytes} bytes.");
        }

        $clientName = $this->getClientFilename();
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));

        if ($allowedExtensions !== []) {
            $allowed = array_map('strtolower', $allowedExtensions);
            if (!in_array($ext, $allowed, true)) {
                throw new RuntimeException("Invalid file extension: .{$ext}");
            }
        }

        if ($allowedExtensions !== []) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($this->tmpName);

             if ($ext === 'jpg' || $ext === 'jpeg') {
                if (!str_starts_with($mime, 'image/jpeg')) throw new RuntimeException("MIME mismatch (expected image/jpeg, got {$mime})");
            }
            if ($ext === 'png') {
                if (!str_starts_with($mime, 'image/png')) throw new RuntimeException("MIME mismatch (expected image/png, got {$mime})");
            }
            if ($ext === 'gif') {
                if (!str_starts_with($mime, 'image/gif')) throw new RuntimeException("MIME mismatch (expected image/gif, got {$mime})");
            }
            if ($ext === 'webp') {
                if (!str_starts_with($mime, 'image/webp')) throw new RuntimeException("MIME mismatch (expected image/webp, got {$mime})");
            }
            if ($ext === 'svg') {
                if (!str_starts_with($mime, 'image/svg') && $mime !== 'text/xml' && $mime !== 'text/html') throw new RuntimeException("MIME mismatch (expected image/svg+xml, got {$mime})");
            }
            if ($ext === 'pdf') {
                if (!str_starts_with($mime, 'application/pdf') && $mime !== 'text/pdf') throw new RuntimeException("MIME mismatch (expected application/pdf, got {$mime})");
            }
        }

        return true;
    }

    public function getStreamContent(): string
    {
        if ($this->error !== UPLOAD_ERR_OK || !file_exists($this->tmpName)) {
            return '';
        }
        return (string) file_get_contents($this->tmpName);
    }
}
