<?php
declare(strict_types=1);

namespace System\Services\Queue\Adapters;

use PDO;
use System\Services\Queue\Contracts\QueueInterface;
use System\Services\Queue\JobPayload;
use System\Services\Queue\ReservedJob;
use System\Services\Queue\FailureInfo;

final class DatabaseQueue implements QueueInterface
{
    public function __construct(private PDO $pdo, private DatabaseQueueConfig $cfg = new DatabaseQueueConfig())
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function push(JobPayload $job, int $delaySeconds = 0): string
    {
        $id = bin2hex(random_bytes(16));
        $payload = [
            'id'=>$id,'queue'=>$job->queue ?: $this->cfg->queue,'handler'=>$job->handler,
            'data'=>$job->data,'maxAttempts'=>$job->maxAttempts,'timeoutSeconds'=>$job->timeoutSeconds,
            'meta'=>$job->meta,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $sql = "INSERT INTO {$this->cfg->jobsTable} (id, queue, payload, attempts, available_at, reserved_at, visibility_timeout, created_at)
                VALUES (:id,:queue,:payload,0,:available,NULL,NULL,:created)";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':id'=>$id, ':queue'=>$payload['queue'], ':payload'=>$json,
            ':available'=>time()+max(0,$delaySeconds), ':created'=>time(),
        ]);
        return $id;
    }

    public function reserve(int $waitSeconds = 3, int $visibilityTimeoutSeconds = 60): ?ReservedJob
    {
        $deadline = microtime(true) + max(0,$waitSeconds);
        do {
            $this->reclaimExpired();

            // Claim a job atomically: lock the candidate row with FOR UPDATE inside a
            // transaction so a concurrent worker cannot select and reserve the same job.
            $startedTransaction = !$this->pdo->inTransaction();
            if ($startedTransaction) {
                $this->pdo->beginTransaction();
            }
            try {
                $sel = $this->pdo->prepare(
                    "SELECT id, queue, payload, attempts, available_at FROM {$this->cfg->jobsTable}
                     WHERE queue=:queue AND reserved_at IS NULL AND available_at <= :now
                     ORDER BY available_at ASC LIMIT 1 FOR UPDATE"
                );
                $sel->execute([':queue'=>$this->cfg->queue, ':now'=>time()]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    if ($startedTransaction) { $this->pdo->commit(); }
                    usleep(150000);
                    continue;
                }

                $id = (string)$row['id'];
                $attempts = (int)$row['attempts'] + 1;
                $upd = $this->pdo->prepare(
                    "UPDATE {$this->cfg->jobsTable}
                     SET attempts=:attempts, reserved_at=:reserved, visibility_timeout=:vt
                     WHERE id=:id AND reserved_at IS NULL"
                );
                $upd->execute([':attempts'=>$attempts, ':reserved'=>time(), ':vt'=>time()+max(1,$visibilityTimeoutSeconds), ':id'=>$id]);

                // Only treat the job as ours if this worker actually flipped the row.
                if ($upd->rowCount() !== 1) {
                    if ($startedTransaction) { $this->pdo->commit(); }
                    continue;
                }

                if ($startedTransaction) { $this->pdo->commit(); }
            } catch (\Throwable $e) {
                if ($startedTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }

            $payload = json_decode((string)$row['payload'], true);
            if (!is_array($payload)) $payload = [];
            $payload['attempts'] = $attempts;

            return new ReservedJob(
                id: $id,
                queue: (string)($row['queue'] ?? $this->cfg->queue),
                handler: (string)($payload['handler'] ?? ''),
                payloadDecoded: $payload,
                attempts: $attempts,
                reservedAtUnix: time(),
                availableAtUnix: (int)$row['available_at']
            );
        } while (microtime(true) < $deadline);
        return null;
    }

    public function ack(string $jobId): void
    {
        $st = $this->pdo->prepare("DELETE FROM {$this->cfg->jobsTable} WHERE id=:id");
        $st->execute([':id'=>$jobId]);
    }

    public function release(string $jobId, int $delaySeconds = 0): void
    {
        $st = $this->pdo->prepare(
            "UPDATE {$this->cfg->jobsTable} SET reserved_at=NULL, visibility_timeout=NULL, available_at=:a WHERE id=:id"
        );
        $st->execute([':id'=>$jobId, ':a'=>time()+max(0,$delaySeconds)]);
    }

    public function fail(string $jobId, FailureInfo $info): void
    {
        $this->pdo->beginTransaction();
        $sel = $this->pdo->prepare("SELECT queue, payload, attempts FROM {$this->cfg->jobsTable} WHERE id=:id");
        $sel->execute([':id'=>$jobId]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        $queue = $row ? (string)$row['queue'] : $this->cfg->queue;
        $payload = $row ? (string)$row['payload'] : json_encode(['id'=>$jobId]);
        $attempts = $row ? (int)$row['attempts'] : 0;

        $ins = $this->pdo->prepare(
            "INSERT INTO {$this->cfg->failedJobsTable} (job_id, queue, payload, attempts, error_class, error_message, error_stack, failed_at)
             VALUES (:job_id,:queue,:payload,:attempts,:ec,:em,:es,:failed)"
        );
        $ins->execute([
            ':job_id'=>$jobId, ':queue'=>$queue, ':payload'=>$payload, ':attempts'=>$attempts,
            ':ec'=>$info->errorClass, ':em'=>$info->message, ':es'=>$info->stack, ':failed'=>$info->failedAtUnix,
        ]);

        $del = $this->pdo->prepare("DELETE FROM {$this->cfg->jobsTable} WHERE id=:id");
        $del->execute([':id'=>$jobId]);
        $this->pdo->commit();
    }

    private function reclaimExpired(): void
    {
        $st = $this->pdo->prepare(
            "UPDATE {$this->cfg->jobsTable} SET reserved_at=NULL, visibility_timeout=NULL
             WHERE reserved_at IS NOT NULL AND visibility_timeout IS NOT NULL AND visibility_timeout <= :now"
        );
        $st->execute([':now'=>time()]);
    }
}
