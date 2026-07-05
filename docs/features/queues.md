# Queues

Yantra's queue system (`System\Services\Queue`) lets you push work out of the request cycle and process it in a background worker. A job is described by an immutable `JobPayload` (handler class + data), stored through any `QueueInterface` adapter (database, file, or Redis), and executed by a `Worker` that reserves jobs, dispatches them to registered `JobHandlerInterface` implementations, and retries failures with a pluggable backoff policy.

```php
use System\Services\Queue\JobPayload;

// Push a job
$jobId = $queue->push(new JobPayload(
    handler: SendEmailJob::class,
    data: ['email' => 'user@example.com', 'template' => 'welcome'],
));

// Delayed job (run no earlier than 60 seconds from now)
$queue->push(
    new JobPayload(handler: ProcessOrderJob::class, data: ['order_id' => 123]),
    delaySeconds: 60
);
```

## JobPayload

`JobPayload` is a readonly value object:

```php
new JobPayload(
    handler: SendEmailJob::class,   // FQCN of the handler
    data: [],                       // array<string,mixed> passed to handle()
    queue: 'default',               // logical queue name
    maxAttempts: 5,                 // total attempts before the job is failed
    timeoutSeconds: 60,             // exposed to the handler via JobContext
    meta: []                        // arbitrary string metadata
);
```

## Defining Job Handlers

Handlers implement `System\Services\Queue\Contracts\JobHandlerInterface`:

```php
use System\Services\Queue\Contracts\JobHandlerInterface;
use System\Services\Queue\JobContext;

class SendEmailJob implements JobHandlerInterface
{
    public function handle(array $data, JobContext $ctx): void
    {
        // $data  — the payload data
        // $ctx   — readonly: jobId, queue, attempt, maxAttempts, timeoutSeconds, extras
        sendWelcomeEmail($data['email'], $data['template']);
    }
}
```

Throwing from `handle()` marks the attempt as failed and triggers the retry flow; returning normally acknowledges the job.

## The QueueInterface Contract

All adapters implement `System\Services\Queue\Contracts\QueueInterface`:

```php
interface QueueInterface
{
    public function push(JobPayload $job, int $delaySeconds = 0): string;

    public function reserve(int $waitSeconds = 3, int $visibilityTimeoutSeconds = 60): ?ReservedJob;

    public function ack(string $jobId): void;

    public function release(string $jobId, int $delaySeconds = 0): void;

    public function fail(string $jobId, FailureInfo $info): void;
}
```

- **`push`** — enqueue a job, returns the job id.
- **`reserve`** — claim the next available job for up to `visibilityTimeoutSeconds`; returns `null` when nothing is available within `waitSeconds`. The returned `ReservedJob` is readonly: `id`, `queue`, `handler`, `payloadDecoded`, `attempts`, `reservedAtUnix`, `availableAtUnix`.
- **`ack`** — job succeeded, remove it.
- **`release`** — put the job back (optionally delayed) for another attempt.
- **`fail`** — permanently fail the job with a `FailureInfo` (readonly `errorClass`, `message`, `stack`, `failedAtUnix`; build via `FailureInfo::fromThrowable($e)`).

## Adapters

| Adapter | Constructor | Use case |
|---|---|---|
| `DatabaseQueue` | `new DatabaseQueue(PDO $pdo, DatabaseQueueConfig $cfg = new DatabaseQueueConfig())` | Reliable, no extra infrastructure. Config: `jobsTable = 'jobs'`, `failedJobsTable = 'failed_jobs'`, `queue = 'default'`. |
| `FileQueue` | `new FileQueue(string $baseDir)` | Simple, development-friendly. |
| `RedisQueue` | `new RedisQueue(\Redis $redis, string $queue = 'default', string $prefix = 'yantra:queue:')` | Fast, production-ready. |

All live under `System\Services\Queue\Adapters`.

```php
use System\Services\Queue\Adapters\DatabaseQueue;
use System\Services\Queue\Adapters\DatabaseQueueConfig;

$queue = new DatabaseQueue($pdo, new DatabaseQueueConfig(
    jobsTable: 'jobs',
    failedJobsTable: 'failed_jobs',
    queue: 'emails'
));
```

## Consuming a Queue: the Worker

`System\Services\Queue\Worker` is the consumer. You construct it with a queue, a serializer, and a retry policy, register a handler instance per handler FQCN, then run it:

```php
use System\Services\Queue\Worker;
use System\Services\Queue\Serializers\JsonSerializer;
use System\Services\Queue\Retry\ExponentialBackoff;

$worker = new Worker(
    queue: $queue,
    serializer: new JsonSerializer(),
    retryPolicy: new ExponentialBackoff(),
    contextExtras: ['env' => 'production']   // exposed as JobContext->extras
);

$worker->registerHandler(SendEmailJob::class, new SendEmailJob());
$worker->registerHandler(ProcessOrderJob::class, new ProcessOrderJob($orderService));

// Blocking loop: reserve -> handle -> ack/release/fail, forever
$worker->work(sleepMs: 250, waitSeconds: 3, visibilityTimeoutSeconds: 60);

// Or process at most one job (returns true if a job was processed)
$worker->runOnce();
```

Each iteration the worker:

1. Calls `$queue->reserve()` — if `null`, `work()` sleeps `sleepMs` and polls again.
2. Looks up the handler by the job's handler FQCN (throws `HandlerNotRegisteredException` if not registered).
3. Builds a `JobContext` from the decoded payload and calls `$handler->handle($data, $ctx)`.
4. On success: `ack()`. On any `Throwable`: if `attempts < maxAttempts`, `release()` with a delay from the retry policy; otherwise `fail()` with `FailureInfo::fromThrowable($e)`.

Run the loop as a supervised long-lived process (systemd, supervisor, k8s), e.g. a small `worker.php` script that wires the adapter and calls `work()`.

## Retry & Backoff

The retry delay comes from `RetryPolicyInterface::delaySeconds(ReservedJob $job, \Throwable $e): int`. The built-in `ExponentialBackoff`:

```php
new ExponentialBackoff(
    baseDelaySeconds: 5,     // attempt 1 -> ~5s, attempt 2 -> ~10s, attempt 3 -> ~20s ...
    maxDelaySeconds: 600,    // cap
    jitter: 0.2              // +/-20% randomization to avoid thundering herds
);
```

Implement the interface yourself for fixed delays, per-exception policies, etc.

## Serialization

Payloads are encoded/decoded through `SerializerInterface` (`encode(array): string`, `decode(string): array`); the provided `JsonSerializer` covers typical needs.

::: warning Gotchas
- Every handler class must be registered on the worker with `registerHandler()` — an unregistered handler throws `HandlerNotRegisteredException`, which counts as a failed attempt and goes through the retry flow.
- There is no built-in `queue:work` CLI command — you write and supervise your own worker script.
- `maxAttempts` and `timeoutSeconds` travel inside the payload; the visibility timeout you pass to `reserve()`/`work()` is what actually protects against workers dying mid-job. Keep it comfortably above your longest job.
- `Worker::work()` never returns — deploy it under a process supervisor and restart it on code changes.
- The mail service ships its own separate, simpler queue (`System\Services\Email\Queue`) — see [Mail](/features/mail).
:::

## Related

- [Mail](/features/mail) — the email-specific queue and `Mailer::queueSend()`
- [Queued email cookbook](/cookbook/queued-email)
- [Scheduler](/features/scheduler) — cron-style recurring tasks
- [Database getting started](/database/getting-started) — PDO setup for `DatabaseQueue`
