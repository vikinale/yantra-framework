<?php
declare(strict_types=1);

namespace System\Core;

use InvalidArgumentException;
use System\Config;
use System\Controller;
use System\Http\Request;
use System\Http\Response;
use System\Http\Json;
use System\Http\ApiResponse;
use System\Security\Crypto;
use System\Security\Jwt\Jwt;

/**
 * APIController (Yantra optimized)
 *
 * This controller provides:
 *  - consistent JSON response output
 *  - request validation helpers
 *  - optional stateless authentication helpers (JWT / API key / Basic)
 *
 * Recommended: enforce auth via Router middleware in production.
 */
abstract class APIController extends Controller
{
    /**
     * Authenticated identity/claims (JWT payload or API key identity).
     */
    protected ?array $apiIdentity = null;

    public function __construct(Request $request, Response $response, bool $requireAuth = false)
    {
        parent::__construct($request, $response);

        // API controllers typically do NOT enforce same-origin CSRF.
        // If you need CORS, do it via middleware (System\Security\Cors).
        if ($requireAuth) {
            $this->authenticateRequest('bearer');
        }
    }

    /**
     * Primary JSON response helper (kept for convenience).
     */
    protected function jsonResponse(mixed $data, int $statusCode = 200, array $headers = []): void
    {
        ApiResponse::json($this->response, $data, $statusCode, $headers);
        $this->terminate();
    }

    /**
     * Validate JSON body and required fields. Returns decoded array.
     */
    protected function validateJsonRequest(array $requiredFields = [], bool $allowEmptyBody = false): array
    {
        $raw = '';
        if (method_exists($this->request, 'getPsrRequest')) {
            $psr = $this->request->getPsrRequest();
            if ($psr) $raw = (string)$psr->getBody();
        }

        try {
            $data = Json::decodeBody($raw, true, $allowEmptyBody);
        } catch (\Throwable) {
            ApiResponse::error($this->response, 'invalid_json', 'Invalid JSON payload', 400);
            $this->terminate();
        }

        if (!is_array($data)) {
            ApiResponse::error($this->response, 'invalid_json', 'JSON payload must be an object', 400);
            $this->terminate();
        }

        foreach ($requiredFields as $f) {
            if (!is_string($f) || $f === '') continue;
            if (!array_key_exists($f, $data)) {
                ApiResponse::error($this->response, 'missing_field', "Missing required field: {$f}", 422, ['field' => $f]);
                $this->terminate();
            }
        }

        return $data;
    }

    /**
     * Authenticate request using method:
     *  - 'bearer'  => JWT HS256
     *  - 'basic'   => HTTP Basic (config)
     *  - 'api_key' => X-API-Key / Authorization: ApiKey <key>
     *  - 'any'     => bearer OR api_key OR basic
     */
    protected function authenticateRequest(string $method = 'bearer'): void
    {
        $method = strtolower(trim($method));
        $ok = false;

        if ($method === 'any') {
            $ok = $this->authenticateBearerToken()
                || $this->authenticateApiKey()
                || $this->authenticateBasicAuth();
        } elseif ($method === 'bearer') {
            $ok = $this->authenticateBearerToken();
        } elseif ($method === 'api_key') {
            $ok = $this->authenticateApiKey();
        } elseif ($method === 'basic') {
            $ok = $this->authenticateBasicAuth();
        } else {
            throw new InvalidArgumentException('Unknown auth method: ' . $method);
        }

        if (!$ok) {
            ApiResponse::error($this->response, 'unauthorized', 'Unauthorized', 401);
            $this->terminate();
        }
    }

    /**
     * Bearer JWT (HS256)
     * Reads: Authorization: Bearer <token>
     */
    protected function authenticateBearerToken(): bool
    {
        $auth = $this->getHeader('Authorization') ?? '';
        if (!is_string($auth) || stripos($auth, 'Bearer ') !== 0) {
            return false;
        }

        $token = trim(substr($auth, 7));
        if ($token === '') {
            return false;
        }

        $cfg = Config::get('app');
        $secret = (string)($cfg['api_jwt_secret'] ?? '');
        if ($secret === '') {
            // misconfigured; treat as auth fail (do not leak detail)
            return false;
        }

        $payload = Jwt::decodeHS256($token, $secret, 30, false);
        if (!is_array($payload)) {
            return false;
        }

        $this->apiIdentity = $payload;
        return true;
    }

    /**
     * HTTP Basic Auth
     *
     * Config keys (app config):
     *  - api_basic_user
     *  - api_basic_pass (plain) OR api_basic_pass_hash (recommended)
     */
    protected function authenticateBasicAuth(): bool
    {
        $auth = $this->getHeader('Authorization') ?? '';
        if (!is_string($auth) || stripos($auth, 'Basic ') !== 0) {
            return false;
        }

        $b64 = trim(substr($auth, 6));
        $decoded = base64_decode($b64, true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return false;
        }

        [$user, $pass] = explode(':', $decoded, 2);

        return $this->isValidBasicAuthCredentials((string)$user, (string)$pass);
    }

    protected function authenticateApiKey(): bool
    {
        // 1) X-API-Key header
        $key = $this->getHeader('X-API-Key');

        // 2) Authorization: ApiKey <key> (optional support)
        if (!is_string($key) || $key === '') {
            $auth = $this->getHeader('Authorization') ?? '';
            if (is_string($auth) && stripos($auth, 'ApiKey ') === 0) {
                $key = trim(substr($auth, 7));
            }
        }

        if (!is_string($key) || trim($key) === '') {
            return false;
        }
        $key = trim($key);

        $cfg = Config::get('app');

        /**
         * Config options:
         *  - api_keys => ['key1','key2'] (plain keys)
         *  - api_key_hashes => ['hash1','hash2'] where hash = hash('sha256', key) (recommended)
         */
        $plain = $cfg['api_keys'] ?? null;
        $hashes = $cfg['api_key_hashes'] ?? null;

        // Prefer hashes if configured
        if (is_array($hashes) && $hashes !== []) {
            $kHash = hash('sha256', $key);
            foreach ($hashes as $h) {
                if (!is_string($h) || $h === '') continue;
                if (Crypto::hashEquals($h, $kHash)) {
                    $this->apiIdentity = ['type' => 'api_key', 'hash' => $kHash];
                    return true;
                }
            }
            return false;
        }

        // Fallback: plain keys
        if (is_array($plain) && $plain !== []) {
            foreach ($plain as $k) {
                if (!is_string($k) || $k === '') continue;
                if (Crypto::hashEquals($k, $key)) {
                    $this->apiIdentity = ['type' => 'api_key', 'key' => $key];
                    return true;
                }
            }
        }

        return false;
    }

    protected function isValidBasicAuthCredentials(string $user, string $pass): bool
    {
        $cfg = Config::get('app');

        $cfgUser = (string)($cfg['api_basic_user'] ?? '');
        if ($cfgUser === '') {
            return false;
        }

        if (!Crypto::hashEquals($cfgUser, $user)) {
            return false;
        }

        // Prefer hashed password
        $hash = (string)($cfg['api_basic_pass_hash'] ?? '');
        if ($hash !== '') {
            // password_hash compatible (bcrypt/argon)
            return password_verify($pass, $hash);
        }

        $plain = (string)($cfg['api_basic_pass'] ?? '');
        if ($plain === '') {
            return false;
        }

        return Crypto::hashEquals($plain, $pass);
    }

    /**
     * Accessor for controllers to read identity/claims.
     */
    protected function identity(): ?array
    {
        return $this->apiIdentity;
    }
}
