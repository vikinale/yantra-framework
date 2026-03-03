<?php
namespace System\Helpers;

class OriginHelper
{
    /**
     * Compare candidate origin/URL to the site origin.
     */
    public static function isSameOrigin(string $candidate, string $siteOrigin): bool
    {
        $parts = @parse_url($candidate);
        if ($parts === false) return false;

        $scheme = $parts['scheme'] ?? null;
        $host   = $parts['host'] ?? null;
        $port   = $parts['port'] ?? null;

        if (empty($scheme) || empty($host)) {
            // compare raw normalized
            return SecurityHelper::constantTimeEquals(rtrim($siteOrigin, '/'), rtrim($candidate, '/'));
        }

        $candidateOrigin = $scheme . '://' . $host . (!empty($port) ? ':' . $port : '');
        return SecurityHelper::constantTimeEquals($siteOrigin, $candidateOrigin);
    }
}
