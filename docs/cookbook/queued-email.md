# Sending Email Through the Queue

This recipe composes an `EmailMessage`, wraps the send work in a queue job, and dispatches it through the general-purpose [queue system](/features/queues) so the HTTP request returns immediately. The job handler runs later in a background worker, where it rebuilds and sends the message.

```php
use System\Services\Queue\JobPayload;

$queue->push(new JobPayload(
    handler: SendEmailJob::class,
    data: ['to' => 'user@example.com', 'name' => 'John'],
));
```

::: tip Two queues, pick the right one
Yantra ships **two** unrelated queues. This recipe uses the general-purpose `System\Services\Queue` (database/file/Redis adapters, `JobPayload` + handler classes). The mail service also has its **own** lightweight, email-specific queue with `Mailer::queueSend()` — if all you need is "send this email later," that is simpler; see [Mail](/features/mail). Use the general queue (this recipe) when email is one of several job types flowing through the same worker.
:::

## 1. Compose the `EmailMessage`

`EmailMessage` is a **plain property bag, not a fluent builder** — you assign its public properties directly. `from` is a single `Address`; `to`/`cc`/`bcc` are `Address[]` you append to; attachments are built with the `Attachment` factories.

```php
use System\Services\Email\EmailMessage;
use System\Services\Email\Address;
use System\Services\Email\Attachment;

$message = new EmailMessage();
$message->from      = new Address('noreply@myapp.com', 'My App');
$message->to[]      = new Address('user@example.com', 'John Doe');
$message->subject   = 'Your receipt';
$message->htmlBody  = '<h1>Thanks!</h1><p>Your order is confirmed.</p>';
$message->textBody  = 'Thanks! Your order is confirmed.';
$message->attachments[] = Attachment::fromFile('/path/to/receipt.pdf', 'receipt.pdf');
```

`new Address(string $email, ?string $name = null)` validates the email and throws `InvalidArgumentException` on invalid input, so validate user-supplied addresses first. At least one of `htmlBody`/`textBody` is required. See [Mail](/features/mail) for the complete `EmailMessage`/`Address`/`Attachment` reference.

## 2. Push a job onto the queue

You don't serialize the whole `EmailMessage` into the job — pass the primitive data the handler needs, and let the handler rebuild the message. `push()` takes a `JobPayload` plus an optional `delaySeconds`.

```php
use System\Services\Queue\JobPayload;

$queue->push(
    new JobPayload(
        handler: SendEmailJob::class,
        data: [
            'to'      => 'user@example.com',
            'name'    => 'John Doe',
            'subject' => 'Your receipt',
            'html'    => '<h1>Thanks!</h1><p>Your order is confirmed.</p>',
        ],
    ),
    delaySeconds: 0,   // send now; e.g. 300 to hold for 5 minutes
);
```

`push(JobPayload $job, int $delaySeconds = 0): string` returns the job id. `JobPayload` is a readonly value object — its data must be plain, serializable values (the default `JsonSerializer` encodes it), which is why you pass strings, not `Address` objects.

## 3. Write the job handler

Handlers implement `System\Services\Queue\Contracts\JobHandlerInterface`, whose single method is `handle(array $data, JobContext $ctx): void`. Inside, rebuild the `EmailMessage` from `$data` and send it through your `Mailer`.

```php
<?php
namespace App\Jobs;

use System\Services\Queue\Contracts\JobHandlerInterface;
use System\Services\Queue\JobContext;
use System\Services\Email\EmailMessage;
use System\Services\Email\Address;
use System\Services\Email\Mailer;

class SendEmailJob implements JobHandlerInterface
{
    public function __construct(private Mailer $mailer) {}

    public function handle(array $data, JobContext $ctx): void
    {
        $message = new EmailMessage();
        $message->from     = new Address('noreply@myapp.com', 'My App');
        $message->to[]     = new Address($data['to'], $data['name'] ?? null);
        $message->subject  = $data['subject'] ?? '';
        $message->htmlBody = $data['html'] ?? '';

        // Throwing marks the attempt failed and triggers the retry flow.
        $this->mailer->send($message);
    }
}
```

`$ctx` (readonly `jobId`, `queue`, `attempt`, `maxAttempts`, `timeoutSeconds`, `extras`) tells the handler which attempt it is on — useful for logging or giving up early. Returning normally acknowledges the job; throwing any exception releases it for a retry (up to the payload's `maxAttempts`) and then fails it.

## 4. Register the handler on the worker

The worker maps each handler FQCN to an instance, then runs a reserve-handle-ack loop. Wire this in a small supervised `worker.php` script.

```php
use System\Services\Queue\Worker;
use System\Services\Queue\Serializers\JsonSerializer;
use System\Services\Queue\Retry\ExponentialBackoff;

$worker = new Worker(
    queue: $queue,
    serializer: new JsonSerializer(),
    retryPolicy: new ExponentialBackoff(),
);

$worker->registerHandler(SendEmailJob::class, new SendEmailJob($mailer));

$worker->work(sleepMs: 250, waitSeconds: 3, visibilityTimeoutSeconds: 60);   // blocking loop
```

See [Queues](/features/queues) for adapters (`DatabaseQueue`, `FileQueue`, `RedisQueue`), the worker lifecycle, and retry/backoff details.

::: warning Gotchas
- **`EmailMessage` is not fluent.** `$message->to('x@y.com')` fatals — assign properties: `$message->to[] = new Address('x@y.com')`.
- **Job data must be serializable.** Pass strings/arrays in `JobPayload::data`, not `Address`/`Attachment`/`EmailMessage` objects — the payload is JSON-encoded. Rebuild the message inside `handle()`.
- **The handler signature is exactly `handle(array $data, JobContext $ctx): void`** — the payload data is the first argument, the context is the second.
- **Every handler must be registered** on the worker with `registerHandler()`, or reserving its job throws `HandlerNotRegisteredException` (counted as a failed attempt).
- **`new Address()` throws on invalid emails** — validate recipient addresses before enqueuing, or the job will fail every retry inside `handle()`.
- **You run and supervise the worker.** There is no built-in `queue:work` command — deploy `work()` under systemd/supervisor.
:::

## Related

- [Queues](/features/queues) — the general-purpose queue, adapters, and worker
- [Mail](/features/mail) — `EmailMessage`, transports, and the email-specific `queueSend()`
- [Scheduler](/features/scheduler) — for recurring, time-based sends
