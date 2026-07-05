<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Security;

/**
 * SSRF-prevention policy for outbound webhook delivery.
 *
 * Both relaxations default to the safe (blocked) state. Users posting to
 * legitimate plain-http or internal endpoints must opt in explicitly.
 */
final class WebhookSecurityConfig
{
    private function __construct(
        public readonly bool $allowHttp,
        public readonly bool $allowPrivateNetworks
    ) {}

    public static function defaults(): self
    {
        return new self(
            allowHttp: false,
            allowPrivateNetworks: false
        );
    }

    public function withAllowHttp(bool $allow = true): self
    {
        return new self($allow, $this->allowPrivateNetworks);
    }

    public function withAllowPrivateNetworks(bool $allow = true): self
    {
        return new self($this->allowHttp, $allow);
    }
}
