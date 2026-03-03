<?php
declare(strict_types=1);

namespace System\Services\Email\Bounce;

use System\Services\\Email\Contracts\BounceHandlerInterface;
use System\Services\\Email\Contracts\BounceBatchParserInterface;
use System\Services\\Email\Contracts\BounceParserInterface;
use System\Services\\Email\Exceptions\BounceException;

/**
 * Bounce processor is invoked by the application when it receives a bounce signal.
 * The app controls inbound routes/webhooks/mailbox parsing and then passes the raw payload here.
 */
final class BounceProcessor
{
    /**
     * @param array<int, BounceParserInterface|BounceBatchParserInterface> $parsers
     */
    public function __construct(
        private array $parsers,
        private BounceHandlerInterface $handler
    ) {}

    /**
     * Process payloads that may contain multiple events.
     * Parsers that implement BounceBatchParserInterface may return multiple BounceEvent.
     *
     * @return BounceEvent[]
     */
    public function processBatch(string $payload, array $headers = []): array
    {
        foreach ($this->parsers as $p) {
            if ($p instanceof BounceBatchParserInterface) {
                $events = $p->parseBatch($payload, $headers);
                if (is_array($events) && !empty($events)) {
                    foreach ($events as $ev) {
                        $this->handler->handle($ev);
                    }
                    return $events;
                }
            }
        }

        // fallback: single event parsers
        foreach ($this->parsers as $p) {
            if ($p instanceof BounceParserInterface) {
                $event = $p->parse($payload, $headers);
                if ($event !== null) {
                    $this->handler->handle($event);
                    return [$event];
                }
            }
        }

        throw new BounceException('Unrecognized bounce payload');
    }

    public function process(string $payload, array $headers = []): BounceEvent
    {
        $events = $this->processBatch($payload, $headers);
        return $events[0];
    }
}
