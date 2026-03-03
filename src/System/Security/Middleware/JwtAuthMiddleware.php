<?php
declare(strict_types=1);

namespace System\Security\Middleware;

use System\Http\Request;
use System\Http\Response;

final class JwtAuthMiddleware
{
    /**
     * @param string[] $requiredRoles
     */
    public function __construct(
        private string $publicKeyPem,
        private array $requiredRoles = [],
        private int $leewaySeconds = 30
    ) {}

    public function __invoke(Request $req, Response $res, callable $next, array $params): Response
    {
        $auth = $this->getHeader($req, 'Authorization');
        $token = $this->extractBearer($auth);
        if ($token === '') {
            return $this->deny($res, 401, 'Missing Bearer token.');
        }

        $payload = $this->verifyRs256($token);
        if ($payload === null) {
            return $this->deny($res, 401, 'Invalid token.');
        }

        // Time checks
        $now = time();
        $exp = isset($payload['exp']) ? (int)$payload['exp'] : 0;
        $nbf = isset($payload['nbf']) ? (int)$payload['nbf'] : 0;

        if ($nbf !== 0 && ($now + $this->leewaySeconds) < $nbf) {
            return $this->deny($res, 401, 'Token not active yet.');
        }
        if ($exp !== 0 && ($now - $this->leewaySeconds) >= $exp) {
            return $this->deny($res, 401, 'Token expired.');
        }

        // Role check (optional)
        if ($this->requiredRoles !== []) {
            $roles = $payload['roles'] ?? $payload['role'] ?? [];
            if (is_string($roles)) $roles = [$roles];
            if (!is_array($roles)) $roles = [];

            $roles = array_map('strval', $roles);
            $ok = false;
            foreach ($this->requiredRoles as $r) {
                if (in_array($r, $roles, true)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return $this->deny($res, 403, 'Forbidden (missing role).');
            }
        }

        // Optional: attach identity for controllers (stateless, in-memory only)
        // If your Request supports attribute storage, use it; otherwise skip safely.
        if (method_exists($req, 'setAttribute')) {
            $req->set('auth.jwt', $payload);
        }

        return $next($req, $res);
    }

    private function extractBearer(string $auth): string
    {
        $auth = trim($auth);
        if ($auth === '') return '';
        if (stripos($auth, 'Bearer ') !== 0) return '';
        return trim(substr($auth, 7));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function verifyRs256(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;

        [$h64, $p64, $s64] = $parts;

        $header  = $this->jsonDecode($this->b64urlDecode($h64));
        $payload = $this->jsonDecode($this->b64urlDecode($p64));
        $sig     = $this->b64urlDecode($s64);

        if (!is_array($header) || !is_array($payload)) return null;

        $alg = (string)($header['alg'] ?? '');
        if ($alg !== 'RS256') return null;

        $data = $h64 . '.' . $p64;
        $ok = openssl_verify($data, $sig, $this->publicKeyPem, OPENSSL_ALGO_SHA256);

        return ($ok === 1) ? $payload : null;
    }

    private function b64urlDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        $out = base64_decode($s, true);
        return $out === false ? '' : $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function jsonDecode(string $json): ?array
    {
        $json = trim($json);
        if ($json === '') return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function getHeader(Request $req, string $name): string
    {
        if (method_exists($req, 'header')) {
            $v = $req->getHeader($name);
            return is_string($v) ? $v : '';
        }
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string)($_SERVER[$key] ?? '');
    }

    private function deny(Response $res, int $code, string $message): Response
    {
        return $res->withStatus($code)
                   ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                   ->text($message, $code);
    }
}
