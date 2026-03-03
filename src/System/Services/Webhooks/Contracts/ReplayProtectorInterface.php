<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Contracts;

/**
 * Optional Application-owned replay protection.
 *
 * Inbound webhooks can be replayed if an attacker captures a signed request.
 * Timestamp tolerance reduces risk. A replay protector can further prevent
 * reusing the same signature/eventId within the tolerance window.
 */
interface ReplayProtectorInterface
{
    /**
     * Returns true if this signature (or eventId) has already been seen.
     */
    public function hasSeen(string $endpointId, string $fingerprint): bool;

    /**
     * Record this signature/eventId as seen for a TTL window.
     */
    public function markSeen(string $endpointId, string $fingerprint, int $ttlSeconds): void;
}
