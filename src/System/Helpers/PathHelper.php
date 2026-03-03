<?php

namespace System\Helpers;

/**
 * Class PathHelper
 *
 * A collection of utilities for safe filesystem path manipulation.
 * Cross-platform support (Windows/Unix).
 */
class PathHelper
{
    /**
     * Join multiple path segments into a clean, normalized path.
     */
    public static function join(string ...$segments): string
    {
        $parts = [];

        foreach ($segments as $seg) {
            if ($seg === '' || $seg === null) {
                continue;
            }
            $parts[] = trim($seg, "/\\");
        }

        return self::normalize(implode(DIRECTORY_SEPARATOR, $parts));
    }

    /**
     * Normalize path slashes and remove duplicate separators.
     * Example:
     *   "/var/www//html"  => "/var/www/html"
     *   "C:\\test\\\dir"  => "C:/test/dir"
     */
    public static function normalize(string $path): string
    {
        // Convert backslashes to forward slashes
        $path = str_replace('\\', '/', $path);

        // Remove duplicate slashes (except for protocol like "http://")
        $path = preg_replace('#(?<!:)/{2,}#', '/', $path);

        // Remove trailing slash unless root
        if ($path !== '/' && preg_match('#^.+/$#', $path)) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    /**
     * Determine if the path is absolute.
     * Supports:
     *  - Unix: /etc/config
     *  - Windows: C:\test
     *  - Windows UNC: \\Shared\Drive
     */
    public static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Unix absolute
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        // Windows drive letter: C:\...
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return true;
        }

        return false;
    }

    /**
     * Convert a relative path to absolute using a base.
     */
    public static function toAbsolute(string $path, string $baseDirectory): string
    {
        if (self::isAbsolute($path)) {
            return self::normalize($path);
        }

        return self::join(self::normalize($baseDirectory), $path);
    }

    /**
     * Ensure a directory exists, creating it recursively.
     */
    public static function ensureDirectory(string $dir, int $permissions = 0755): bool
    {
        $dir = self::normalize($dir);

        if (is_dir($dir)) {
            return true;
        }

        return mkdir($dir, $permissions, true);
    }

    /**
     * Remove ".." segments to prevent directory traversal.
     */
    public static function safePath(string $path): string
    {
        $path = self::normalize($path);

        $parts = explode('/', $path);
        $safe = [];

        foreach ($parts as $p) {
            if ($p === '..') {
                continue; // skip insecure reference
            }
            if ($p === '.') {
                continue;
            }
            $safe[] = $p;
        }

        return implode('/', $safe);
    }

    /**
     * Get the parent directory of a path.
     */
    public static function parent(string $path): string
    {
        $path = self::normalize($path);
        return self::normalize(dirname($path));
    }

    /**
     * Get filename from a path.
     */
    public static function filename(string $path): string
    {
        $path = self::normalize($path);
        return basename($path);
    }

    /**
     * Get extension from filename or path.
     */
    public static function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Remove extension from a filename.
     */
    public static function withoutExtension(string $path): string
    {
        $ext = self::extension($path);
        if ($ext === '') {
            return $path;
        }

        return substr($path, 0, -(strlen($ext) + 1));
    }
}
