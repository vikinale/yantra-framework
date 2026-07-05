<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Security;

/**
 * Validates outbound webhook target URLs to prevent SSRF.
 *
 * Blocks non-http(s) schemes (file://, gopher://, ...), URLs without a host,
 * and hosts given as IP literals inside private/reserved/loopback/link-local
 * ranges (e.g. the cloud metadata endpoint 169.254.169.254). Hostname-based
 * DNS-rebinding is intentionally out of scope; only literal-IP cases are
 * blocked here.
 */
final class UrlSafety
{
    /**
     * Returns null when the URL is safe to dispatch, or a short machine-readable
     * reason string describing why it was rejected.
     */
    public static function validate(string $url, ?WebhookSecurityConfig $config = null): ?string
    {
        $config = $config ?? WebhookSecurityConfig::defaults();

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'invalid_url';
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'http') {
            if (!$config->allowHttp) {
                return 'insecure_scheme_http';
            }
        } elseif ($scheme !== 'https') {
            return 'disallowed_scheme_' . $scheme;
        }

        $host = $parts['host'];
        if ($host === '') {
            return 'missing_host';
        }

        // Hosts may be wrapped in brackets for IPv6 literals: [::1]
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            if (!$config->allowPrivateNetworks && self::isBlockedIp($literal)) {
                return 'blocked_private_ip';
            }
        }

        return null;
    }

    /**
     * True when the given IP literal falls in a private/reserved/loopback/
     * link-local range that must not be reached from an outbound webhook.
     */
    private static function isBlockedIp(string $ip): bool
    {
        // filter_var covers RFC1918 (10/8, 172.16/12, 192.168/16), loopback,
        // link-local (169.254/16, fe80::/10), unique-local (fc00::/7), etc.
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return true;
        }

        // 0.0.0.0/8 and the IPv6 loopback ::1 are not covered by the flags on
        // every PHP build, so block them explicitly.
        if (str_starts_with($ip, '0.')) {
            return true;
        }
        if (in_array(strtolower($ip), ['::1', '::'], true)) {
            return true;
        }

        return false;
    }
}
