<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Security;

use System\Services\Webhooks\Contracts\ReplayProtectorInterface;
use System\Services\Webhooks\Exceptions\SignatureException;

/**
 * Inbound webhook verification.
 *
 * Your app must provide:
 * - endpointId (how you map request->endpoint)
 * - secretProvider: fn(endpointId) => secret
 *
 * Verify scheme:
 * - signature header contains "v1=<hex>"
 * - timestamp header is unix seconds
 * - signed message: "<timestamp>.<rawBody>"
 */
final class WebhookVerifier
{
    /** @var callable(string):string */
    private $secretProvider;
    private Signer $signer;

    public function __construct(
        callable $secretProvider,
        private readonly SignatureConfig $config,
        private readonly ?ReplayProtectorInterface $replayProtector = null
    ) {
        $this->secretProvider = $secretProvider;
        $this->signer = new Signer();
    }

    /**
     * @param array<string,string|string[]> $headers case-insensitive keys recommended
     * @throws SignatureException
     */
    public function verify(string $endpointId, array $headers, string $rawBody, ?int $now = null): void
    {
        $now = $now ?? time();

        $sig = $this->getHeader($headers, $this->config->signatureHeader);
        $ts  = $this->getHeader($headers, $this->config->timestampHeader);

        if ($sig === null || $sig === '') {
            throw SignatureException::missingHeader($this->config->signatureHeader);
        }
        if ($ts === null || $ts === '') {
            throw SignatureException::missingHeader($this->config->timestampHeader);
        }
        if (!ctype_digit($ts)) {
            throw SignatureException::invalidTimestamp($ts);
        }

        $timestamp = (int)$ts;
        $delta = abs($now - $timestamp);
        if ($delta > $this->config->toleranceSeconds) {
            throw SignatureException::timestampOutsideTolerance($timestamp, $now, $this->config->toleranceSeconds);
        }

        $secret = ($this->secretProvider)($endpointId);
        if ($secret === '') {
            throw SignatureException::missingSecret($endpointId);
        }

        $expected = $this->signer->sign($secret, $timestamp, $rawBody);

        if (!ConstantTime::equals($expected, $sig)) {
            throw SignatureException::signatureMismatch();
        }

        // Optional replay protection
        if ($this->replayProtector !== null) {
            $eventId = $this->getHeader($headers, $this->config->eventIdHeader);
            $fingerprint = $this->signer->fingerprint($eventId, $sig);
            if ($this->replayProtector->hasSeen($endpointId, $fingerprint)) {
                throw SignatureException::replayDetected();
            }
            $this->replayProtector->markSeen($endpointId, $fingerprint, $this->config->toleranceSeconds);
        }
    }

    /**
     * @param array<string,string|string[]> $headers
     */
    private function getHeader(array $headers, string $name): ?string
    {
        // normalize keys
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, $name) !== 0) continue;
            if (is_array($v)) return (string)($v[0] ?? '');
            return (string)$v;
        }
        return null;
    }
}
