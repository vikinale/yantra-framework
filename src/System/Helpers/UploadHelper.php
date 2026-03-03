<?php
namespace System\Helpers;

use Psr\Http\Message\UploadedFileInterface;
use System\Http\UploadedFile;

class UploadHelper
{
    /**
     * Store an uploaded file (PSR/System wrapper or $_FILES array) into a directory
     * with unified naming + collision suffix logic.
     *
     * @param \System\Http\UploadedFile|UploadedFileInterface|array<string,mixed> $upload
     * @param array<int,string> $allowedExts  e.g. ['png','jpg','webp','ico']
     * @return array{ok:bool, path?:string, filename?:string, ext?:string, error?:string}
     */
        public static function store(
            UploadedFile|UploadedFileInterface|array $upload,
            string $destDir,
            array $allowedExts = [],
            int $maxBytes = 0,
            ?string $preferredFilename = null,
            int $maxTries = 10_000,
            ?string $dateFolderFormat = null
        ): array{
        $subdir = '';
        $finalDir = rtrim($destDir, '/\\');

        if ($dateFolderFormat) {
            $subdir = date($dateFolderFormat);
            $subdir = strtolower($subdir);
            $finalDir .= DIRECTORY_SEPARATOR . $subdir;
        }

        self::ensureDir($destDir);

        // --- normalize metadata ---
        [$origName, $size, $error, $mime] = self::readUploadMeta($upload, $preferredFilename);

        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'No file selected.'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload error: ' . $error];
        }
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'Empty file.'];
        }
        if ($maxBytes > 0 && $size > $maxBytes) {
            return ['ok' => false, 'error' => 'File is too large.'];
        }
        if ($origName === '') {
            return ['ok' => false, 'error' => 'Missing filename.'];
        }

        $info = self::sanitizeFilename($origName);
        $name = $info['name'];
        $ext  = $info['ext'];

        if ($ext === '') {
            return ['ok' => false, 'error' => 'Missing file extension.'];
        }
        if ($allowedExts && !in_array($ext, $allowedExts, true)) {
            return ['ok' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowedExts)];
        }

        // Server-side MIME check
        // 1. If it's our enhanced UploadedFile, use its validate() if available
        if ($upload instanceof UploadedFile && method_exists($upload, 'validate')) {
             // We can use the validate method we added earlier, but it throws. 
             // Let's just do a manual check here to imply "store" logic.
             // Actually, let's look at the source path to check mime.
        }

        $sourcePath = null;
        if ($upload instanceof UploadedFile || $upload instanceof UploadedFileInterface) {
             // PSR-7 doesn't easily give path, but we can read stream.
             // For simple check, we rely on the extension check above + basic stream sniff if needed.
             // BUT, if we have the wrapper, we can try to use it.
        } elseif (is_array($upload) && isset($upload['tmp_name'])) {
             $sourcePath = $upload['tmp_name'];
        }

        // If we have a local path (e.g. $_FILES), check real mime
        if ($sourcePath && file_exists($sourcePath)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $realMime = $finfo->file($sourcePath);
            
            // 1. Trust but verify: If client says image, it must be image.
            if ($mime && str_starts_with($mime, 'image/') && !str_starts_with($realMime, 'image/') && $ext !== 'svg') {
                 return ['ok' => false, 'error' => "Invalid file content (MIME mismatch: $realMime)."];
            }

            // 2. Extension vs Content: If extension is common image format, real content must be image.
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) && !str_starts_with($realMime, 'image/')) {
                 return ['ok' => false, 'error' => "File extension '$ext' does not match file content ($realMime)."];
            }
        }

        $preferred = $name . '.' . $ext;
        
        $target = $finalDir . DIRECTORY_SEPARATOR . $preferred;
        
        $finalPath = self::resolveUniquePath($target, $maxTries);
        $finalName = basename($finalPath);

        // --- move/copy ---
        try {
            self::moveUploadTo($upload, $finalPath);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Failed to store uploaded file.'];
        }
        $url =  path_to_url($finalPath);
        return ['ok' => true, 'path' => $finalPath, 'url'=>$url, 'filename' => $finalName, 'ext' => $ext];
    }

    /** @return array{name:string,ext:string} */
    private static function sanitizeFilename(string $filename): array
    {
        $base = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename);
        $base = trim((string)$base, '._-');
        if ($base === '') $base = 'file.bin';

        $ext  = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        $name = pathinfo($base, PATHINFO_FILENAME);

        // safer name
        $name = preg_replace('/[^A-Za-z0-9_\-]/', '-', (string)$name);
        $name = trim((string)$name, '-');
        if ($name === '') $name = 'file';

        return ['name' => $name, 'ext' => $ext];
    }

    public static function resolveUniquePath(string $targetPath, int $maxTries = 10_000): string
    {
        if (!file_exists($targetPath)) return $targetPath;

        $dir  = dirname($targetPath);
        $base = basename($targetPath);

        $ext  = pathinfo($base, PATHINFO_EXTENSION);
        $name = pathinfo($base, PATHINFO_FILENAME);
        $extPart = ($ext !== '') ? ('.' . $ext) : '';

        for ($i = 1; $i <= $maxTries; $i++) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $name . '-' . $i . $extPart;
            if (!file_exists($candidate)) return $candidate;
        }

        throw new \RuntimeException('Unable to find a unique filename.');
    }

    private static function ensureDir(string $dir): void
    {
        if ($dir === '') throw new \RuntimeException('Upload directory is empty.');
        if (class_exists(PathHelper::class)) {
            PathHelper::ensureDirectory($dir);
            return;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Upload directory is not writable.');
        }
    }

    /**
     * @param \System\Http\UploadedFile|UploadedFileInterface|array<string,mixed> $upload
     * @return array{0:string,1:int,2:int,3:string} [name,size,error,mime]
     */
    private static function readUploadMeta(UploadedFile|UploadedFileInterface|array $upload, ?string $preferredFilename): array
    {
        if ($upload instanceof UploadedFile) {
            return [
                $preferredFilename ?? $upload->getClientFilename(),
                $upload->getSize(),
                $upload->getError(),
                $upload->getClientMediaType(),
            ];
        }

        if ($upload instanceof UploadedFileInterface) {
            return [
                $preferredFilename ?? (string)$upload->getClientFilename(),
                (int)($upload->getSize() ?? 0),
                (int)$upload->getError(),
                (string)$upload->getClientMediaType(),
            ];
        }

        // $_FILES-style
        $name  = $preferredFilename ?? (string)($upload['name'] ?? '');
        $size  = (int)($upload['size'] ?? 0);
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        $mime  = (string)($upload['type'] ?? '');

        return [$name, $size, $error, $mime];
    }

    /**
     * @param UploadedFile|UploadedFileInterface|array<string,mixed> $upload
     */
    private static function moveUploadTo(UploadedFile|UploadedFileInterface|array $upload, string $finalPath): void
    {
        if ($upload instanceof UploadedFile) {
            $upload->moveTo($finalPath);
            return;
        }

        if ($upload instanceof UploadedFileInterface) {
            $upload->moveTo($finalPath);
            return;
        }

        // $_FILES-style
        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($tmp === '') throw new \RuntimeException('Missing tmp_name.');

        if (!@move_uploaded_file($tmp, $finalPath)) {
            // fallback (sometimes needed for non-SAPI contexts)
            if (!@copy($tmp, $finalPath)) {
                throw new \RuntimeException('Failed to move file.');
            }
        }
    }

    // Keep your deleteDirRecursive() here too and USE it from assembleChunks()
    public static function deleteDirRecursive(string $dir): bool
    {
        if ($dir === '' || !is_dir($dir)) return true;

        $items = @scandir($dir);
        if (!$items) return @rmdir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) self::deleteDirRecursive($path);
            else @unlink($path);
        }
        return @rmdir($dir);
    }

    public static function saveChunk(string $tempBase, string $fileId, int $index, array $file): array
    {
        $fileIdSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $fileId);
        $targetDir  = rtrim($tempBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileIdSafe;
        PathHelper::ensureDirectory($targetDir);

        $chunkDest = $targetDir . DIRECTORY_SEPARATOR . 'chunk_' . $index;
        if (!move_uploaded_file($file['tmp_name'], $chunkDest)) {
            if (!copy($file['tmp_name'], $chunkDest)) {
                return ['status' => false, 'message' => 'Failed to save chunk'];
            }
        }
        return ['status' => true, 'fileId' => $fileIdSafe, 'index' => $index];
    }

    public static function assembleChunks(
        string $tempBase,
        string $uploadsBase,
        string $fileId,
        string $filename,
        ?int $maxSize = null,
        bool $useDateSubfolder = false,
        string $dateFolderFormat = 'MdY' // Jan102026 -> lowercased to jan102026
    ): array {
        $fileIdSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $fileId);
        $tempDir = rtrim($tempBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileIdSafe;

        if (!is_dir($tempDir)) {
            return ['status' => false, 'message' => 'No chunks found'];
        }

        $chunks = [];
        $files = @scandir($tempDir) ?: [];
        foreach ($files as $f) {
            if (preg_match('/^chunk_(\d+)$/', $f, $m)) {
                $chunks[(int)$m[1]] = $tempDir . DIRECTORY_SEPARATOR . $f;
            }
        }

        if (empty($chunks)) {
            self::deleteDirRecursive($tempDir);
            return ['status' => false, 'message' => 'No chunk files found'];
        }

        ksort($chunks);

        // Optional date subfolder
        $finalDir = rtrim($uploadsBase, DIRECTORY_SEPARATOR);
        $subdir = '';
        if ($useDateSubfolder) {
            $subdir = strtolower(date($dateFolderFormat)); // jan102026
            $finalDir .= DIRECTORY_SEPARATOR . $subdir;
        }

        PathHelper::ensureDirectory($finalDir);

        // Sanitize filename -> <name>.<ext>
        $baseName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename);
        $baseName = trim((string)$baseName, '._-');
        if ($baseName === '') $baseName = 'file.bin';

        $ext  = strtolower(pathinfo($baseName, PATHINFO_EXTENSION));
        $name = pathinfo($baseName, PATHINFO_FILENAME);
        if ($ext === '') $ext = 'bin';

        // Preferred final path, then resolve collisions: file.ext -> file-1.ext ...
        $preferredName = $name . '.' . $ext;
        $preferredPath = $finalDir . DIRECTORY_SEPARATOR . $preferredName;

        $finalPath = self::resolveUniquePath($preferredPath, 10_000);
        $finalName = basename($finalPath);
        $tmpPath   = $finalPath . '.part';

        $out = null;

        try {
            $out = fopen($tmpPath, 'c+b');
            if (!$out) {
                return ['status' => false, 'message' => 'Failed to open output file'];
            }

            if (!flock($out, LOCK_EX)) {
                return ['status' => false, 'message' => 'Lock failed'];
            }

            ftruncate($out, 0);
            rewind($out);

            foreach ($chunks as $p) {
                $in = fopen($p, 'rb');
                if (!$in) {
                    return ['status' => false, 'message' => 'Failed to read chunk: ' . basename($p)];
                }

                while (!feof($in)) {
                    $buf = fread($in, 1048576);
                    if ($buf === false) break;
                    if ($buf !== '') fwrite($out, $buf);
                }
                fclose($in);
            }

            fflush($out);
            flock($out, LOCK_UN);
            fclose($out);
            $out = null;

            // Atomic-ish finalize
            if (!@rename($tmpPath, $finalPath)) {
                // Fallback (Windows edge cases)
                if (!@copy($tmpPath, $finalPath)) {
                    return ['status' => false, 'message' => 'Failed to finalize file'];
                }
                @unlink($tmpPath);
            }

            $size = (int)(filesize($finalPath) ?: 0);

            if ($maxSize !== null && $size > $maxSize) {
                @unlink($finalPath);
                return ['status' => false, 'message' => 'File too large'];
            }

            return [
                'status' => true,
                'path'   => $finalPath,
                'size'   => $size,
                'fileId' => $fileIdSafe,  // keep as logical upload id
                'name'   => $finalName,   // actual stored filename
                'subdir' => $subdir,      // '' if disabled
            ];
        } finally {
            if (is_resource($out)) {
                @flock($out, LOCK_UN);
                @fclose($out);
            }

            // Best-effort cleanup
            @unlink($tmpPath);

            // Remove chunk directory reliably (includes remaining files if any)
            self::deleteDirRecursive($tempDir);
        }
    }

    public static function assembleChunks_old(string $tempBase, string $uploadsBase, string $fileId, string $filename, ?int $maxSize = null): array
    {
        $fileIdSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $fileId);
        $tempDir = rtrim($tempBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileIdSafe;
        if (!is_dir($tempDir)) return ['status' => false, 'message' => 'No chunks found'];

        $files = scandir($tempDir);
        $chunks = [];
        foreach ($files as $f) {
            if (preg_match('/^chunk_(\d+)$/', $f, $m)) {
                $chunks[intval($m[1])] = $tempDir . DIRECTORY_SEPARATOR . $f;
            }
        }
        if (empty($chunks)) return ['status' => false, 'message' => 'No chunk files found'];

        ksort($chunks);
        PathHelper::ensureDirectory($uploadsBase);
        $finalName = $fileIdSafe . '_' . preg_replace('/[^A-Za-z0-9_\-\._]/', '_', $filename);
        $finalPath = rtrim($uploadsBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $finalName;
        $tmpPath = $finalPath . '.part';

        $out = fopen($tmpPath, 'c+b');
        if (!$out) return ['status' => false, 'message' => 'Failed to open output file'];

        if (!flock($out, LOCK_EX)) { fclose($out); return ['status' => false, 'message' => 'Lock failed']; }
        ftruncate($out, 0);
        rewind($out);

        foreach ($chunks as $p) {
            $in = fopen($p, 'rb');
            if (!$in) continue;
            while (!feof($in)) {
                fwrite($out, fread($in, 1048576));
            }
            fclose($in);
        }

        fflush($out);
        flock($out, LOCK_UN);
        fclose($out);

        rename($tmpPath, $finalPath);
        $size = filesize($finalPath) ?: 0;

        if ($maxSize !== null && $size > $maxSize) { unlink($finalPath); return ['status' => false, 'message' => 'File too large']; }

        // cleanup
        foreach ($chunks as $p) @unlink($p);
        @rmdir($tempDir);

        return ['status' => true, 'path' => $finalPath, 'size' => $size, 'fileId' => $fileIdSafe];
    }
}
