# Webhooks

Yantra ships a complete outbound webhook subsystem under `System\Services\Webhooks` — signed, retry-aware HTTP event delivery to application-defined endpoints. You create an immutable `WebhookEvent`, the `WebhookDispatcher` resolves target endpoints through an application-owned resolver, signs each request with HMAC-SHA256, guards against SSRF, sends it over cURL, and hands back a per-endpoint retry decision. An inbound `WebhookVerifier` is included for the receiving side of the same signature scheme.

```php
use System\Services\Webhooks\Events\WebhookEvent;

$event = WebhookEvent::create('user.created', [
    'user_id' => 42,
    'email'   => 'jane@example.com',
]);

$results = $dispatcher->dispatch($event);
```

## Events

`WebhookEvent::create(string $type, array $payload, ?int $occurredAt = null, array $meta = [])` builds an immutable event with a random UUIDv4 `id`. `$occurredAt` is unix seconds and defaults to `time()`. All properties (`id`, `type`, `occurredAt`, `payload`, `meta`) are public readonly.

`EventSerializer` turns the event into the JSON body receivers get — keep your payloads stable, receivers depend on this shape:

```json
{
  "id": "8f14e45f-…",
  "type": "user.created",
  "occurred_at": 1751673600,
  "payload": { "user_id": 42, "email": "jane@example.com" },
  "meta": {}
}
```

## Endpoints

`WebhookEndpoint` (in `System\Services\Webhooks\Value`) is an immutable value object your application constructs — endpoints typically live in your database or config:

```php
use System\Services\Webhooks\Value\WebhookEndpoint;

$endpoint = new WebhookEndpoint(
    id: 'ep_billing',
    url: 'https://partner.example.com/hooks',
    secret: 'whsec_...',
    enabled: true,
    headers: ['X-Custom' => 'value'],   // extra headers to send
    eventTypes: ['user.created', 'user.deleted'], // allowlist; [] = all events
    timeoutSeconds: 10,
    connectTimeoutSeconds: 5
);
```

`$endpoint->accepts(string $eventType): bool` returns `false` when the endpoint is disabled, and otherwise checks the `eventTypes` allowlist — an **empty list accepts every event type**.

### How endpoints are resolved

There is no built-in endpoint registry. The dispatcher asks an application-owned resolver implementing `EndpointResolverInterface`:

```php
use System\Services\Webhooks\Contracts\EndpointResolverInterface;
use System\Services\Webhooks\Events\WebhookEvent;
use System\Services\Webhooks\Value\WebhookEndpoint;

final class DbEndpointResolver implements EndpointResolverInterface
{
    /** @return WebhookEndpoint[] */
    public function resolveEndpoints(WebhookEvent $event): array
    {
        // Load from DB, config, or an external system.
        return [/* WebhookEndpoint instances */];
    }
}
```

The dispatcher additionally re-checks `accepts()` for each resolved endpoint, so your resolver can return a broad set and let the event-type filter do the rest.

## The dispatcher

```php
use System\Services\Webhooks\Delivery\WebhookDispatcher;

$dispatcher = new WebhookDispatcher(
    endpointResolver: new DbEndpointResolver(),
    observer: null,             // optional DeliveryObserverInterface
    signatureConfig: null,      // defaults to SignatureConfig::defaults()
    retryPolicy: null,          // defaults to RetryPolicy::exponentialBackoff()
    transport: null,            // defaults to CurlTransport
    securityConfig: null        // defaults to WebhookSecurityConfig::defaults()
);

$results = $dispatcher->dispatch($event);           // attempt 1
$results = $dispatcher->dispatch($event, attempt: 2); // a retry pass
```

`dispatch(WebhookEvent $event, int $attempt = 1, ?int $queuedAt = null): DispatchResults` sends the event to every resolved endpoint that accepts it, and returns a `DispatchResults` whose `deliveries` array holds one `DeliveryOutcome` per endpoint:

- `$outcome->attempt` — the `DeliveryAttempt` (event, endpoint, attempt number, exact headers and body sent)
- `$outcome->result` — the `DeliveryResult` (`ok`, `statusCode`, `responseBody`, `responseHeaders`, `error`, `durationMs`, `blocked`)
- `$outcome->retry` — the `RetryDecision` (`shouldRetry`, `delayMs`, `reason`)

An optional `DeliveryObserverInterface` (`onAttempt()` / `onResult()`) lets you persist every attempt and response for auditing.

## Signing

Every outbound request is signed by `Signer` with **HMAC-SHA256** over the string `"<timestamp>.<rawBody>"` using the endpoint's secret. The signature value has the form `v1=<hex>` and travels in three headers, configured by `SignatureConfig::defaults()`:

| Header | Content |
| --- | --- |
| `X-Yantra-Signature` | `v1=<hmac-sha256 hex>` |
| `X-Yantra-Timestamp` | unix seconds used in the signed message |
| `X-Yantra-Event-Id` | the event's UUID (useful for replay protection / idempotency) |

Header names and the verification tolerance (default **300 seconds**) are customizable:

```php
use System\Services\Webhooks\Security\SignatureConfig;

$config = SignatureConfig::defaults()
    ->withToleranceSeconds(600)
    ->withHeaders('X-My-Signature', 'X-My-Timestamp', 'X-My-Event-Id');
```

### Verifying inbound webhooks

`WebhookVerifier` implements the receiving side of the same scheme — checks the timestamp tolerance, recomputes the HMAC, compares in constant time, and optionally rejects replays through a `ReplayProtectorInterface`:

```php
use System\Services\Webhooks\Security\SignatureConfig;
use System\Services\Webhooks\Security\WebhookVerifier;

$verifier = new WebhookVerifier(
    secretProvider: fn (string $endpointId): string => lookupSecret($endpointId),
    config: SignatureConfig::defaults()
);

// Throws SignatureException on any failure
$verifier->verify('ep_billing', $request->headers(), $rawBody);
```

## Retry policy

`RetryPolicy::exponentialBackoff(int $maxAttempts = 6, int $baseDelayMs = 500, int $maxDelayMs = 60_000, float $jitterRatio = 0.2)` produces the default policy. `decide(int $attempt, DeliveryResult $result)` returns a `RetryDecision`:

- **Retry** on network errors (`statusCode === 0`), HTTP `429`, and `5xx` — with delay `baseDelayMs * 2^(attempt-1)`, capped at `maxDelayMs`, plus/minus up to `jitterRatio` jitter.
- **No retry** on success, on any other `4xx`, when `maxAttempts` is reached, or when the delivery was locally `blocked` (SSRF) — blocked deliveries are permanent failures.

The dispatcher itself does **not** sleep or re-dispatch. It computes the decision and hands it to you: schedule a follow-up call to `dispatch($event, $attempt + 1)` after `$outcome->retry->delayMs` milliseconds. There is no built-in queue wiring — a queue job (see [Queues](/features/queues)) is the natural place to run dispatches and re-enqueue retries with the suggested delay.

## SSRF protection

Before any request is sent, `UrlSafety::validate()` checks the endpoint URL against a `WebhookSecurityConfig`. By default it rejects:

- non-HTTPS schemes (`http://`, `file://`, `gopher://`, …)
- URLs without a host
- IP-literal hosts in private / reserved / loopback / link-local ranges (RFC1918, `169.254.169.254` cloud metadata, `::1`, `0.0.0.0/8`, …)

A blocked URL produces `DeliveryResult::blocked('ssrf_blocked:<reason>')` for that endpoint only — other endpoints in the same dispatch still run. Both relaxations are explicit opt-ins:

```php
use System\Services\Webhooks\Security\WebhookSecurityConfig;

$security = WebhookSecurityConfig::defaults()
    ->withAllowHttp()             // permit plain http:// targets
    ->withAllowPrivateNetworks(); // permit private/reserved IP literals
```

Hostname-based DNS rebinding is documented as out of scope — only literal-IP targets are blocked.

## Full example: dispatch a `user.created` webhook

```php
use System\Services\Webhooks\Contracts\EndpointResolverInterface;
use System\Services\Webhooks\Delivery\RetryPolicy;
use System\Services\Webhooks\Delivery\WebhookDispatcher;
use System\Services\Webhooks\Events\WebhookEvent;
use System\Services\Webhooks\Value\WebhookEndpoint;

$resolver = new class implements EndpointResolverInterface {
    public function resolveEndpoints(WebhookEvent $event): array
    {
        return [
            new WebhookEndpoint(
                id: 'ep_crm',
                url: 'https://crm.example.com/yantra-hooks',
                secret: 'whsec_crm',
                eventTypes: ['user.created']
            ),
        ];
    }
};

$dispatcher = new WebhookDispatcher(
    endpointResolver: $resolver,
    retryPolicy: RetryPolicy::exponentialBackoff(maxAttempts: 4)
);

$event = WebhookEvent::create('user.created', [
    'user_id' => 42,
    'email'   => 'jane@example.com',
]);

$results = $dispatcher->dispatch($event);

foreach ($results->deliveries as $outcome) {
    if ($outcome->result->ok) {
        continue; // delivered
    }
    if ($outcome->retry->shouldRetry) {
        // Persist and re-run later:
        // dispatch($event, $outcome->attempt->attempt + 1)
        // after $outcome->retry->delayMs milliseconds.
    }
}
```

::: warning Gotchas
- **Delivery is synchronous.** `dispatch()` blocks until every endpoint has been attempted once. Run it from a queue worker for anything user-facing, and implement the retry loop yourself using `RetryDecision::$delayMs`.
- **HTTPS only by default.** Plain `http://` endpoints are silently blocked (`ssrf_blocked:insecure_scheme_http`) unless you pass a `WebhookSecurityConfig` with `withAllowHttp()`.
- **`attempt` is your bookkeeping.** The dispatcher never increments it — pass the correct attempt number on each retry pass or `maxAttempts` will never trigger.
- **Blocked results are never retried**, even though `ok` is `false`. Check `DeliveryResult::$blocked` (or the retry reason) to distinguish policy blocks from transient failures.
- **Empty `eventTypes` means "all events"**, not "no events".
:::

## Related

- [Queues](/features/queues)
- [Scheduler](/features/scheduler)
- [Crypto](/security/crypto)
- [Security overview](/security/overview)
