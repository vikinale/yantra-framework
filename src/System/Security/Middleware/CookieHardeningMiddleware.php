<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;

final class CookieHardeningMiddleware
{
    /**
     * @param string $sameSite Default SameSite policy: 'Lax'|'Strict'|'None'
     * @param bool   $httpOnlyDefault Default HttpOnly for cookies unless excluded
     * @param string[] $httpOnlyFalseNames Cookies that must NOT be HttpOnly (e.g., csrf_token if JS needs it)
     * @param bool   $rewriteSetCookieHeaders If true, rewrite outgoing Set-Cookie headers after $next()
     */
    public function __construct(
        private string $sameSite = 'Lax',
        private bool $httpOnlyDefault = true,
        private array $httpOnlyFalseNames = ['csrf_token'],
        private bool $rewriteSetCookieHeaders = true
    ) {}

    public function __invoke(Request $req, Response $res, callable $next, array $params): void
    {
        // 1) Harden PHP session cookie behavior (must run BEFORE session_start()).
        $this->hardenPhpSessionIni();

        // 2) Execute the rest of the pipeline.
        $next();

        // 3) Rewrite Set-Cookie headers to enforce flags (best-effort).
        if ($this->rewriteSetCookieHeaders && !headers_sent()) {
            $this->rewriteSetCookieHeaders();
        }
    }

    private function hardenPhpSessionIni(): void
    {
        // Strict mode helps prevent session fixation via uninitialized IDs.
        @ini_set('session.use_strict_mode', '1');

        // Reduce exposure to XSS.
        @ini_set('session.cookie_httponly', '1');

        // Only send session cookie over HTTPS if HTTPS is detected.
        @ini_set('session.cookie_secure', $this->isHttps() ? '1' : '0');

        // SameSite for session cookies.
        // PHP expects exact value in many versions; keep it canonical.
        $ss = $this->normalizeSameSite($this->sameSite);
        @ini_set('session.cookie_samesite', $ss);

        // If you use subdomains, consider setting cookie domain centrally in config, not here.
        // @ini_set('session.cookie_domain', '.example.com');
    }

    private function rewriteSetCookieHeaders(): void
    {
        $headers = headers_list();
        if (!$headers) return;

        $setCookies = [];
        foreach ($headers as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                $setCookies[] = trim(substr($h, strlen('Set-Cookie:')));
            }
        }

        if ($setCookies === []) return;

        // Remove all existing Set-Cookie headers, then re-add hardened.
        header_remove('Set-Cookie');

        foreach ($setCookies as $cookieLine) {
            $hardened = $this->hardenCookieLine($cookieLine);
            header('Set-Cookie: ' . $hardened, false);
        }
    }

    private function hardenCookieLine(string $cookieLine): string
    {
        // Parse name=value first
        $parts = array_map('trim', explode(';', $cookieLine));
        if ($parts === []) return $cookieLine;

        $nameValue = array_shift($parts);
        $name = $this->extractCookieName($nameValue);

        // Collect existing attributes (case-insensitive keys)
        $attrs = [];
        $flags = [];

        foreach ($parts as $p) {
            if ($p === '') continue;

            // Flag-style attribute (Secure, HttpOnly)
            if (stripos($p, 'secure') === 0 && (strlen($p) === 6)) {
                $flags['Secure'] = true;
                continue;
            }
            if (stripos($p, 'httponly') === 0 && (strlen($p) === 8)) {
                $flags['HttpOnly'] = true;
                continue;
            }

            // key=value attributes
            $kv = explode('=', $p, 2);
            $k = trim($kv[0] ?? '');
            $v = trim($kv[1] ?? '');

            if ($k === '') continue;

            $attrs[strtolower($k)] = [$k, $v];
        }

        // Enforce SameSite if missing
        if (!isset($attrs['samesite'])) {
            $ss = $this->normalizeSameSite($this->sameSite);
            $attrs['samesite'] = ['SameSite', $ss];
        }

        // Enforce Secure if HTTPS (and if SameSite=None, Secure is required)
        $ssValue = isset($attrs['samesite']) ? strtolower($attrs['samesite'][1] ?? '') : '';
        $needsSecure = $this->isHttps() || ($ssValue === 'none');
        if ($needsSecure && empty($flags['Secure'])) {
            $flags['Secure'] = true;
        }

        // Enforce HttpOnly by default unless excluded
        $httpOnlyWanted = $this->httpOnlyDefault && !in_array($name, $this->httpOnlyFalseNames, true);
        if ($httpOnlyWanted && empty($flags['HttpOnly'])) {
            $flags['HttpOnly'] = true;
        }

        // Rebuild cookie header
        $out = $nameValue;

        // Preserve common attributes in typical order
        $order = ['expires', 'max-age', 'domain', 'path', 'samesite'];

        foreach ($order as $key) {
            if (isset($attrs[$key])) {
                [$K, $V] = $attrs[$key];
                $out .= '; ' . $K . '=' . $V;
                unset($attrs[$key]);
            }
        }

        // Append any other attributes that existed
        foreach ($attrs as [$K, $V]) {
            if ($V === '') {
                $out .= '; ' . $K;
            } else {
                $out .= '; ' . $K . '=' . $V;
            }
        }

        // Append flags last
        if (!empty($flags['Secure'])) {
            $out .= '; Secure';
        }
        if (!empty($flags['HttpOnly'])) {
            $out .= '; HttpOnly';
        }

        return $out;
    }

    private function extractCookieName(string $nameValue): string
    {
        $pos = strpos($nameValue, '=');
        if ($pos === false) return trim($nameValue);
        return trim(substr($nameValue, 0, $pos));
    }

    private function normalizeSameSite(string $s): string
    {
        $s = ucfirst(strtolower(trim($s)));
        return in_array($s, ['Lax', 'Strict', 'None'], true) ? $s : 'Lax';
    }

    private function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
        return false;
    }
}
