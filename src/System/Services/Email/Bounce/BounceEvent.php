<?php
declare(strict_types=1);

namespace System\Services\Email\Bounce;

final class BounceEvent
{
    public function __construct(
        public readonly string $email,
        public readonly string $type,          // hard|soft|complaint|unknown
        public readonly string $provider,      // ses|mailgun|sendgrid|custom...
        public readonly ?string $reason = null,
        public readonly ?string $messageId = null,
        public readonly ?int $timestamp = null,
        public readonly array $raw = []
    ) {}
}
