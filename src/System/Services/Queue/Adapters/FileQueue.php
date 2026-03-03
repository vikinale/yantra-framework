<?php
declare(strict_types=1);

namespace System\Services\Queue\Adapters;

use System\Services\\Queue\Contracts\QueueInterface;
use System\Services\\Queue\JobPayload;
use System\Services\\Queue\ReservedJob;
use System\Services\\Queue\FailureInfo;
use System\Services\\Queue\Exceptions\QueueException;

final class FileQueue implements QueueInterface
{
    private string $pending;
    private string $reserved;
    private string $failed;

    public function __construct(private string $baseDir)
    {
        $d = rtrim($baseDir, '/\\');
        $this->pending = $d . DIRECTORY_SEPARATOR . 'pending';
        $this->reserved = $d . DIRECTORY_SEPARATOR . 'reserved';
        $this->failed = $d . DIRECTORY_SEPARATOR . 'failed';
        foreach ([$this->pending, $this->reserved, $this->failed] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new QueueException("Cannot create dir: {$dir}");
            }
        }
    }

    public function push(JobPayload $job, int $delaySeconds = 0): string
    {
        $id = bin2hex(random_bytes(16));
        $availableAt = time() + max(0, $delaySeconds);

        $payload = [
            'id' => $id,
            'queue' => $job->queue,
            'handler' => $job->handler,
            'data' => $job->data,
            'maxAttempts' => $job->maxAttempts,
            'timeoutSeconds' => $job->timeoutSeconds,
            'meta' => $job->meta,
            'attempts' => 0,
            'availableAt' => $availableAt,
            'createdAt' => time(),
        ];

        $tmp = $this->pending . DIRECTORY_SEPARATOR . $id . '.tmp';
        $final = $this->pending . DIRECTORY_SEPARATOR . sprintf('%010d-%s.json', $availableAt, $id);
        file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        if (!rename($tmp, $final)) {
            @unlink($tmp);
            throw new QueueException('Enqueue failed');
        }
        return $id;
    }

    public function reserve(int $waitSeconds = 3, int $visibilityTimeoutSeconds = 60): ?ReservedJob
    {
        $deadline = microtime(true) + max(0, $waitSeconds);
        do {
            $this->reclaimExpired();

            $file = $this->pickPending();
            if ($file !== null) {
                $payload = $this->readJson($file);
                $id = (string)($payload['id'] ?? '');
                if ($id === '') { @unlink($file); continue; }

                $payload['attempts'] = (int)($payload['attempts'] ?? 0) + 1;
                $payload['reservedAt'] = time();
                $payload['visibilityTimeout'] = time() + max(1, $visibilityTimeoutSeconds);

                $tmp = $this->reserved . DIRECTORY_SEPARATOR . $id . '.tmp';
                $dst = $this->reserved . DIRECTORY_SEPARATOR . $id . '.json';
                file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                rename($tmp, $dst);
                @unlink($file);

                return new ReservedJob(
                    id: $id,
                    queue: (string)($payload['queue'] ?? 'default'),
                    handler: (string)($payload['handler'] ?? ''),
                    payloadDecoded: $payload,
                    attempts: (int)($payload['attempts'] ?? 1),
                    reservedAtUnix: (int)($payload['reservedAt'] ?? time()),
                    availableAtUnix: (int)($payload['availableAt'] ?? time())
                );
            }

            usleep(150 * 1000);
        } while (microtime(true) < $deadline);

        return null;
    }

    public function ack(string $jobId): void
    {
        @unlink($this->reserved . DIRECTORY_SEPARATOR . $jobId . '.json');
    }

    public function release(string $jobId, int $delaySeconds = 0): void
    {
        $p = $this->reserved . DIRECTORY_SEPARATOR . $jobId . '.json';
        if (!is_file($p)) return;

        $payload = $this->readJson($p);
        @unlink($p);

        $payload['availableAt'] = time() + max(0, $delaySeconds);
        unset($payload['reservedAt'], $payload['visibilityTimeout']);

        $dst = $this->pending . DIRECTORY_SEPARATOR . sprintf('%010d-%s.json', (int)$payload['availableAt'], $jobId);
        file_put_contents($dst, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    public function fail(string $jobId, FailureInfo $info): void
    {
        $p = $this->reserved . DIRECTORY_SEPARATOR . $jobId . '.json';
        $payload = is_file($p) ? $this->readJson($p) : ['id' => $jobId];
        @unlink($p);

        $payload['failed'] = [
            'errorClass' => $info->errorClass,
            'message' => $info->message,
            'stack' => $info->stack,
            'failedAt' => $info->failedAtUnix,
        ];

        file_put_contents($this->failed . DIRECTORY_SEPARATOR . $jobId . '.json', json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private function pickPending(): ?string
    {
        $now = time();
        $files = glob($this->pending . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files);
        foreach ($files as $f) {
            $bn = basename($f);
            $ts = (int)substr($bn, 0, 10);
            if ($ts <= $now) return $f;
        }
        return null;
    }

    private function reclaimExpired(): void
    {
        $now = time();
        $files = glob($this->reserved . DIRECTORY_SEPARATOR . '*.json') ?: [];
        foreach ($files as $f) {
            $payload = $this->readJson($f);
            $vt = (int)($payload['visibilityTimeout'] ?? 0);
            if ($vt > 0 && $vt <= $now) {
                $id = (string)($payload['id'] ?? '');
                if ($id === '') { @unlink($f); continue; }
                @unlink($f);
                $payload['availableAt'] = time();
                unset($payload['reservedAt'], $payload['visibilityTimeout']);
                $dst = $this->pending . DIRECTORY_SEPARATOR . sprintf('%010d-%s.json', (int)$payload['availableAt'], $id);
                file_put_contents($dst, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            }
        }
    }

    /** @return array<string,mixed> */
    private function readJson(string $file): array
    {
        $raw = file_get_contents($file);
        if ($raw === false) throw new QueueException("Cannot read: {$file}");
        $arr = json_decode($raw, true);
        if (!is_array($arr)) throw new QueueException("Invalid JSON: {$file}");
        return $arr;
    }
}
