<?php
declare(strict_types=1);

namespace System\Services\Email;

final class Attachment
{
    private function __construct(
        public readonly string $filename,
        public readonly string $contentType,
        public readonly string $contentBase64
    ) {}

    public static function fromString(string $filename, string $bytes, string $contentType = 'application/octet-stream'): self
    {
        return new self($filename, $contentType, base64_encode($bytes));
    }

    
public static function fromBase64(string $filename, string $contentBase64, string $contentType = 'application/octet-stream'): self
{
    // Light validation: must be decodable.
    if (base64_decode($contentBase64, true) === false) {
        throw new \InvalidArgumentException('Invalid base64 content for attachment: ' . $filename);
    }
    return new self($filename, $contentType, $contentBase64);
}

public static function fromFile(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Attachment file not found: {$path}");
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Failed to read attachment file: {$path}");
        }
        $filename = $filename ?? basename($path);
        $contentType = $contentType ?? (mime_content_type($path) ?: 'application/octet-stream');
        return self::fromString($filename, $bytes, $contentType);
    }
}
