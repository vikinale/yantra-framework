<?php
namespace System\Helpers;

class UploadHelper
{
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

    public static function assembleChunks(string $tempBase, string $uploadsBase, string $fileId, string $filename, ?int $maxSize = null): array
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
