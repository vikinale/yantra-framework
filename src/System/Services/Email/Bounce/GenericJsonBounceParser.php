<?php
declare(strict_types=1);

namespace System\Services\Email\Bounce;

use System\Services\Email\Contracts\BounceParserInterface;

/**
 * Very generic JSON bounce parser.
 * Expected JSON example:
 * {
 *   "email": "user@example.com",
 *   "type": "hard|soft|complaint",
 *   "provider": "ses|mailgun|sendgrid|custom",
 *   "reason": "Mailbox does not exist",
 *   "messageId": "...",
 *   "timestamp": 1700000000
 * }
 */
final class GenericJsonBounceParser implements BounceParserInterface
{
    public function parse(string $payload, array $headers = []): ?BounceEvent
    {
        $a = json_decode($payload, true);
        if (!is_array($a)) return null;
        if (!isset($a['email']) || !isset($a['type'])) return null;

        $email = (string)$a['email'];
        $type = (string)$a['type'];
        $provider = (string)($a['provider'] ?? 'custom');

        return new BounceEvent(
            email: $email,
            type: $type,
            provider: $provider,
            reason: isset($a['reason']) ? (string)$a['reason'] : null,
            messageId: isset($a['messageId']) ? (string)$a['messageId'] : null,
            timestamp: isset($a['timestamp']) ? (int)$a['timestamp'] : null,
            raw: $a
        );
    }
}
