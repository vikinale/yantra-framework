<?php
declare(strict_types=1);

namespace System\Services\Queue\Adapters;

use System\Services\Queue\Contracts\QueueInterface;
use System\Services\Queue\JobPayload;
use System\Services\Queue\ReservedJob;
use System\Services\Queue\FailureInfo;
use System\Services\Queue\Exceptions\QueueException;

final class RedisQueue implements QueueInterface
{
    private \Redis $redis;
    private string $ready;
    private string $delayed;
    private string $reserved;
    private string $failed;

    public function __construct(\Redis $redis, private string $queue = 'default', private string $prefix = 'yantra:queue:')
    {
        $this->redis = $redis;
        $this->ready = $prefix.$queue.':ready';
        $this->delayed = $prefix.$queue.':delayed';
        $this->reserved = $prefix.$queue.':reserved';
        $this->failed = $prefix.$queue.':failed';
    }

    public function push(JobPayload $job, int $delaySeconds = 0): string
    {
        $id = bin2hex(random_bytes(16));
        $payload = [
            'id'=>$id,'queue'=>$job->queue ?: $this->queue,'handler'=>$job->handler,
            'data'=>$job->data,'maxAttempts'=>$job->maxAttempts,'timeoutSeconds'=>$job->timeoutSeconds,
            'meta'=>$job->meta,'attempts'=>0,'availableAt'=>time()+max(0,$delaySeconds),'createdAt'=>time(),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new QueueException('JSON encode failed');
        if ($delaySeconds > 0) $this->redis->zAdd($this->delayed, $payload['availableAt'], $json);
        else $this->redis->lPush($this->ready, $json);
        return $id;
    }

    public function reserve(int $waitSeconds = 3, int $visibilityTimeoutSeconds = 60): ?ReservedJob
    {
        $this->promoteDelayed();
        $raw = $this->redis->brPop([$this->ready], max(1, $waitSeconds));
        if (!is_array($raw) || !isset($raw[1])) return null;
        $payload = json_decode((string)$raw[1], true);
        if (!is_array($payload)) return null;

        $payload['attempts'] = (int)($payload['attempts'] ?? 0) + 1;
        $payload['reservedAt'] = time();
        $payload['visibilityTimeout'] = time() + max(1, $visibilityTimeoutSeconds);

        $id = (string)($payload['id'] ?? '');
        if ($id === '') throw new QueueException('Missing job id');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new QueueException('JSON encode failed');

        $this->redis->hSet($this->reserved, $id, $json);
        $this->redis->expire($this->reserved, max(3600, $visibilityTimeoutSeconds * 4));

        return new ReservedJob(
            id: $id,
            queue: (string)($payload['queue'] ?? $this->queue),
            handler: (string)($payload['handler'] ?? ''),
            payloadDecoded: $payload,
            attempts: (int)($payload['attempts'] ?? 1),
            reservedAtUnix: (int)($payload['reservedAt'] ?? time()),
            availableAtUnix: (int)($payload['availableAt'] ?? time())
        );
    }

    public function ack(string $jobId): void { $this->redis->hDel($this->reserved, $jobId); }

    public function release(string $jobId, int $delaySeconds = 0): void
    {
        $json = $this->redis->hGet($this->reserved, $jobId);
        $this->redis->hDel($this->reserved, $jobId);
        if (!is_string($json) || $json === '') return;
        $payload = json_decode($json, true);
        if (!is_array($payload)) return;
        $payload['availableAt'] = time() + max(0, $delaySeconds);
        unset($payload['reservedAt'], $payload['visibilityTimeout']);
        $json2 = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json2 === false) return;
        if ($delaySeconds > 0) $this->redis->zAdd($this->delayed, $payload['availableAt'], $json2);
        else $this->redis->lPush($this->ready, $json2);
    }

    public function fail(string $jobId, FailureInfo $info): void
    {
        $json = $this->redis->hGet($this->reserved, $jobId);
        $this->redis->hDel($this->reserved, $jobId);
        $payload = is_string($json) ? json_decode($json, true) : ['id'=>$jobId];
        if (!is_array($payload)) $payload = ['id'=>$jobId];
        $payload['failed'] = ['errorClass'=>$info->errorClass,'message'=>$info->message,'stack'=>$info->stack,'failedAt'=>$info->failedAtUnix];
        $json2 = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json2 === false) return;
        $this->redis->lPush($this->failed, $json2);
    }

    private function promoteDelayed(): void
    {
        $now = time();
        for ($i=0; $i<200; $i++) {
            $items = $this->redis->zRangeByScore($this->delayed, 0, $now, ['limit'=>[0,1]]);
            if (!is_array($items) || count($items)===0) break;
            $json = (string)$items[0];
            $this->redis->zRem($this->delayed, $json);
            $this->redis->lPush($this->ready, $json);
        }
    }
}
