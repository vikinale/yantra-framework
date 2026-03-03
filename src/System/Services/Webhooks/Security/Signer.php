<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Security;

final class Signer
{
    /**
     * Build v1 signature header value: "v1=<hex>".
     */
    public function sign(string $secret, int $timestamp, string $rawBody): string
    {
        $msg = $timestamp . '.' . $rawBody;
        $hmac = hash_hmac('sha256', $msg, $secret);
        return 'v1=' . $hmac;
    }

    /**
     * Fingerprint used for replay protection.
     * Prefer using event id when present; fallback to signature.
     */
    public function fingerprint(?string $eventId, string $signatureHeaderValue): string
    {
        return $eventId !== null && $eventId !== '' ? 'eid:' . $eventId : 'sig:' . $signatureHeaderValue;
    }
}
