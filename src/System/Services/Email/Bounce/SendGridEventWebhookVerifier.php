<?php
declare(strict_types=1);

namespace System\Services\Email\Bounce;

/**
 * Verifies Twilio SendGrid Signed Event Webhook requests.
 *
 * SendGrid signs the request using ECDSA... the signature is in
 * X-Twilio-Email-Event-Webhook-Signature and the timestamp is in
 * X-Twilio-Email-Event-Webhook-Timestamp.
 *
 * Verification is performed against SHA256(timestamp + rawRequestBody).
 */
final class SendGridEventWebhookVerifier
{
    /**
     * @param string $publicKey Public key as PEM (-----BEGIN PUBLIC KEY-----...) or base64 body.
     * @param string $timestamp Header value X-Twilio-Email-Event-Webhook-Timestamp
     * @param string $signatureB64 Header value X-Twilio-Email-Event-Webhook-Signature
     * @param string $rawBody Raw request bytes (do not JSON re-encode)
     */
    public function verify(string $publicKey, string $timestamp, string $signatureB64, string $rawBody): bool
    {
        $pem = $this->normalizePublicKeyToPem($publicKey);
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }

        $data = $timestamp . $rawBody;
        $sig = base64_decode($signatureB64, true);
        if ($sig === false) {
            return false;
        }

        // OPENSSL_ALGO_SHA256 causes OpenSSL to hash the input with SHA256 before verifying.
        $ok = openssl_verify($data, $sig, $key, OPENSSL_ALGO_SHA256);
        return $ok === 1;
    }

    private function normalizePublicKeyToPem(string $publicKey): string
    {
        $k = trim($publicKey);
        if (str_contains($k, 'BEGIN PUBLIC KEY')) {
            return $k;
        }

        // Assume base64-encoded DER; wrap into PEM.
        $k = preg_replace('/\s+/', '', $k) ?? $k;
        $wrapped = chunk_split($k, 64, "\n");
        return "-----BEGIN PUBLIC KEY-----\n" . $wrapped . "-----END PUBLIC KEY-----\n";
    }
}
