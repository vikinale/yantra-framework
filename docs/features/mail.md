# Mail

Yantra's mail service (`System\Services\Email`) is built around a plain `EmailMessage` object, small `Address`/`Attachment` value objects, and swappable transports behind a single `TransportInterface`. A `Mailer` orchestrates sending: it validates the message, hands it to the transport, and optionally renders templates, notifies observers, or defers delivery through a file-backed email queue with retry and exponential backoff. Bounce webhooks are handled by a `BounceProcessor` with pluggable parsers.

```php
use System\Services\Email\EmailMessage;
use System\Services\Email\Address;
use System\Services\Email\Mailer;
use System\Services\Email\Transport\SmtpTransport;

$email = new EmailMessage();
$email->from     = new Address('noreply@myapp.com', 'My App');
$email->to[]     = new Address('user@example.com', 'John Doe');
$email->subject  = 'Welcome to My App';
$email->htmlBody = '<h1>Welcome!</h1>';
$email->textBody = 'Welcome!';

$mailer = new Mailer(new SmtpTransport('smtp.myapp.com', 587, 'user', 'secret'));
$result = $mailer->send($email);   // TransportResult
```

## Composing Messages

`EmailMessage` is a **plain property-bag object — not a fluent builder**. You assign its public properties directly:

| Property | Type | Notes |
|---|---|---|
| `from` | `?Address` | Required before sending |
| `to`, `cc`, `bcc`, `replyTo` | `Address[]` | Append with `$email->to[] = ...` |
| `subject` | `?string` | Defaults to `''` if left null |
| `htmlBody`, `textBody` | `?string` | At least one required (unless using a provider template) |
| `headers` | `array<string,string>` | Extra MIME headers |
| `attachments` | `Attachment[]` | |
| `meta` | `array` | Provider-agnostic metadata bag (`categories`, `custom_args`, `tags`, `sendgrid` conventions) |

Two helper methods exist on the message: `requireBasicValidity()` (throws `InvalidArgumentException` if `from`, recipients, or a body are missing) and `allRecipients()` (merged `to + cc + bcc`).

### Address

`new Address(string $email, ?string $name = null)` — validates the email with `filter_var()` and throws on invalid input. `format()` produces a header-safe `Name <email>` string (MIME-encoding non-ASCII names).

### Attachment

`Attachment` stores `filename`, `contentType`, and `contentBase64` (all readonly). Build one with a factory:

```php
use System\Services\Email\Attachment;

$email->attachments[] = Attachment::fromFile('/path/to/file.pdf', 'invoice.pdf');
$email->attachments[] = Attachment::fromString('report.csv', $csvBytes, 'text/csv');
$email->attachments[] = Attachment::fromBase64('logo.png', $base64Data, 'image/png');
```

- `fromFile(string $path, ?string $filename = null, ?string $contentType = null)` — reads the file, throws if missing.
- `fromString(string $filename, string $bytes, string $contentType = 'application/octet-stream')`
- `fromBase64(string $filename, string $contentBase64, string $contentType = 'application/octet-stream')` — validates the base64.

## Transports & the Sending Flow

All transports implement `System\Services\Email\Contracts\TransportInterface`:

```php
interface TransportInterface
{
    /** Sends an email immediately. Implementations throw TransportException on failure. */
    public function send(EmailMessage $message): TransportResult;
}
```

Every `send()` starts by calling `$message->requireBasicValidity()`, then delivers and returns a `TransportResult` — a readonly object with `ok` (bool), `messageId`, `statusCode`, `provider` (e.g. `'smtp'`, `'http-api'`), and a `debug` array.

### SmtpTransport

Direct SMTP delivery with optional STARTTLS and AUTH (LOGIN/PLAIN). It builds a MIME document from the message and speaks the protocol over a socket:

```php
use System\Services\Email\Transport\SmtpTransport;

$transport = new SmtpTransport(
    host: 'smtp.myapp.com',
    port: 587,
    username: 'user',
    password: 'secret',
    useStartTls: true,        // default
    timeoutSeconds: 15,       // default
    allowSelfSignedTls: false // default
);
```

### SendGridTransport

Posts to SendGrid's `/v3/mail/send` endpoint with a Bearer token:

```php
use System\Services\Email\Transport\SendGridTransport;

$transport = new SendGridTransport(apiKey: $env['SENDGRID_KEY']);
```

Constructor: `(string $apiKey, ?string $baseUrl = null, ?SendGridPayloadBuilder $builder = null, int $timeoutSeconds = 15)`. SendGrid-specific options (template_id, dynamic template data, personalizations, sandbox mode, ...) go in `$email->meta['sendgrid']`.

### ApiTransport (generic HTTP APIs — Mailgun, SES, Postmark, ...)

`ApiTransport` is a generic HTTP API transport: you provide a base URL, default headers, and a *payload mapper* closure that converts an `EmailMessage` into the provider's request shape. This is how you wire providers like Mailgun:

```php
use System\Services\Email\Transport\ApiTransport;

$transport = new ApiTransport(
    baseUrl: 'https://api.mailgun.net/v3/mg.myapp.com',
    defaultHeaders: ['Authorization' => 'Basic ' . base64_encode('api:' . $mailgunKey)],
    payloadMapper: function ($m) {
        return [
            'path' => 'messages',
            'body' => [
                'from'    => $m->from->format(),
                'to'      => implode(',', array_map(fn ($a) => $a->format(), $m->to)),
                'subject' => $m->subject,
                'html'    => $m->htmlBody,
                'text'    => $m->textBody,
            ],
        ];
    }
);
```

The mapper returns `['method' => ?, 'path' => ?, 'headers' => ?, 'body' => array|string]`; non-2xx responses throw a `TransportException`.

## The Mailer

`Mailer` wraps a transport and adds optional collaborators via fluent configuration:

```php
$mailer = (new Mailer($transport))
    ->withRenderer($templateRenderer)   // TemplateRendererInterface
    ->withQueue($emailQueue)            // Email QueueInterface
    ->withObserver($observer);          // SendObserverInterface

$msg = $mailer->message();              // fresh EmailMessage
$result = $mailer->send($msg);          // immediate send via transport
```

`send()` calls `beforeSend()` on the observer, delivers through the transport, then `afterSend()` — or `onError()` if the transport throws (the exception is rethrown). `SendObserverInterface` defines exactly those three methods: `beforeSend(EmailMessage)`, `afterSend(EmailMessage, TransportResult)`, `onError(EmailMessage, \Throwable)`.

Templates: `render(string $template, array $data = [])` returns a `TemplateResult` (requires a configured renderer), and `applyTemplate($message, $tpl)` copies its subject/html/text/meta onto a message.

## Queued Email

The mail service has its **own lightweight queue** (`System\Services\Email\Queue`, distinct from the general [queue system](/features/queues)) with a file-backed implementation:

```php
use System\Services\Email\Queue\FileQueue;
use System\Services\Email\Queue\Worker;

$queue  = new FileQueue(storage_path('email-queue'));
$mailer = (new Mailer($transport))->withQueue($queue);

// Enqueue instead of sending now — returns a job id
$jobId = $mailer->queueSend($email, delaySeconds: 0, maxAttempts: 5);

// In a separate supervised process:
$worker = new Worker($mailer, $queue, sleepSeconds: 2);
$worker->loop();            // poll forever (or loop(maxSeconds: 55) for cron)
// $worker->runOnce();      // process a single job
```

`queueSend()` serializes the message into a `SendEmailPayload` and pushes a `QueueJob` of type `email.send`. The worker pops jobs, rebuilds the message, and sends it through the mailer. On failure it retries with exponential backoff (`2^attempts` seconds, capped at 300) until `maxAttempts` is reached, after which the job is marked failed. The email `QueueInterface` contract is `push(QueueJob): string`, `pop(): ?QueueJob`, `ack(string $jobId)`, `fail(string $jobId, string $reason)`, `size(): int`.

## Bounce Handling

`BounceProcessor` is invoked by *your* application when a bounce signal arrives (webhook route, mailbox polling, ...). You give it parsers and a handler; it normalizes raw payloads into `BounceEvent` objects and passes each to your handler:

```php
use System\Services\Email\Bounce\BounceProcessor;
use System\Services\Email\Bounce\SendGridEventWebhookParser;
use System\Services\Email\Bounce\GenericJsonBounceParser;

$processor = new BounceProcessor(
    parsers: [new SendGridEventWebhookParser(), new GenericJsonBounceParser()],
    handler: $myBounceHandler   // implements BounceHandlerInterface::handle(BounceEvent): void
);

$events = $processor->processBatch($request->body(), $request->headers()); // BounceEvent[]
// or $processor->process($payload, $headers) for single-event payloads
```

`BounceEvent` is readonly: `email`, `type` (`hard|soft|complaint|unknown`), `provider`, `reason`, `messageId`, `timestamp`, `raw`. Batch-capable parsers implement `BounceBatchParserInterface`; single-event parsers implement `BounceParserInterface`. If no parser recognizes the payload, a `BounceException` is thrown. A `SendGridEventWebhookVerifier` is included for verifying SendGrid webhook signatures.

::: warning Gotchas
- `EmailMessage` is not fluent — `$email->to('x@y.com')` will fatal. Assign properties: `$email->to[] = new Address('x@y.com')`.
- There is no dedicated `MailgunTransport` class; use `ApiTransport` with a payload mapper (as shown above) for Mailgun, SES, Postmark, and other HTTP providers.
- `new Address()` throws on invalid email addresses — validate user input first or be ready to catch `InvalidArgumentException`.
- A body is required unless `meta['sendgrid']['template_id']` is set (provider-side templates).
- The email queue worker must be run and supervised by you (systemd/supervisor/cron) — the framework does not start it.
:::

## Related

- [Queues](/features/queues) — the general-purpose job queue system
- [Queued email cookbook](/cookbook/queued-email)
- [Webhooks](/features/webhooks) — for inbound bounce webhook routing
- [Configuration](/guide/configuration) — where to keep transport credentials
