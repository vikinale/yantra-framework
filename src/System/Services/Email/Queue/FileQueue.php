<?php
declare(strict_types=1);

namespace System\Services\Email\Queue;

use System\Services\Email\Contracts\QueueInterface;
use System\Services\Email\Exceptions\QueueException;

/**
 * Simple file-based queue:
 * - pending/: job json files
 * - processing/: popped jobs
 * - failed/: failed jobs + reason file
 *
 * This is NOT a distributed queue. It's a production-safe option for small/medium deployments
 * where a single worker (or a few workers with file locks) processes jobs.
 */
final class FileQueue implements QueueInterface
{
    private string $pending;
    private string $processing;
    private string $failed;

    public function __construct(private string $dir)
    {
        $this->pending = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'pending';
        $this->processing = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'processing';
        $this->failed = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'failed';

        foreach ([$this->pending, $this->processing, $this->failed] as $d) {
            if (!is_dir($d) && !@mkdir($d, 0775, true)) {
                throw new QueueException("Failed to create queue directory: {$d}");
            }
        }
    }

    public function push(QueueJob $job): string
    {
        $path = $this->pendingPath($job->id);
        $json = json_encode($job->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new QueueException('Failed to encode job JSON');

        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new QueueException("Failed to write job file: {$path}");
        }
        return $job->id;
    }

    public function pop(): ?QueueJob
    {
        $files = glob($this->pending . DIRECTORY_SEPARATOR . '*.json') ?: [];
        if (empty($files)) return null;

        // Basic ordering: oldest first
        usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));

        foreach ($files as $file) {
            $json = @file_get_contents($file);
            if ($json === false) continue;

            $a = json_decode($json, true);
            if (!is_array($a)) continue;

            $job = QueueJob::fromArray($a);
            if ($job->availableAt > time()) {
                continue;
            }

            // Move to processing (atomic rename). If the rename fails another worker
            // likely grabbed the file first, or the move did not complete; skip this
            // job so it is not returned as reserved when it was never moved.
            $processingPath = $this->processingPath($job->id);
            if (!rename($file, $processingPath)) {
                continue;
            }

            return $job;
        }

        return null;
    }

    public function ack(string $jobId): void
    {
        $path = $this->processingPath($jobId);
        if (is_file($path) && !@unlink($path)) {
            throw new QueueException("Failed to ack job (unlink): {$jobId}");
        }
    }

    public function fail(string $jobId, string $reason): void
    {
        $src = $this->processingPath($jobId);
        $dst = $this->failedPath($jobId);
        if (is_file($src)) {
            if (!rename($src, $dst)) {
                throw new QueueException("Failed to move job to failed (rename): {$jobId}");
            }
        } elseif (is_file($this->pendingPath($jobId))) {
            if (!rename($this->pendingPath($jobId), $dst)) {
                throw new QueueException("Failed to move job to failed (rename): {$jobId}");
            }
        }

        if (file_put_contents($dst . '.reason.txt', $reason) === false) {
            throw new QueueException("Failed to write failure reason: {$jobId}");
        }
    }

    public function size(): int
    {
        $files = glob($this->pending . DIRECTORY_SEPARATOR . '*.json') ?: [];
        return count($files);
    }

    public function releaseWithDelay(QueueJob $job, int $delaySeconds): void
    {
        $job->attempts += 1;
        $job->availableAt = time() + max(1, $delaySeconds);
        $this->push($job);
    }

    private function pendingPath(string $id): string { return $this->pending . DIRECTORY_SEPARATOR . $id . '.json'; }
    private function processingPath(string $id): string { return $this->processing . DIRECTORY_SEPARATOR . $id . '.json'; }
    private function failedPath(string $id): string { return $this->failed . DIRECTORY_SEPARATOR . $id . '.json'; }
}
