<?php
declare(strict_types=1);

namespace System\Controllers;

use Exception;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use System\Config;
use System\Http\Request;
use System\Http\Response;
use System\Helpers\JsonHelper;
use System\Helpers\SecurityHelper;
use System\Utilities\Security\TokenManager;

/**
 * Class APIController
 *
 * Simplified/refactored: uses JsonHelper and SecurityHelper,
 * fixes header-setting bug, centralizes JSON parsing and responses.
 */
abstract class APIController extends Controller
{
    /** API version */
    protected string $apiVersion = 'v1';

    /** HTTP status -> phrase map */
    protected array $statusPhrases = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
    ];

    /**
     * @param Request $request
     * @param Response $response
     * @param bool $requireAuth
     * @throws Exception
     */
    public function __construct(Request $request, Response $response, bool $requireAuth = false)
    {
        parent::__construct($request, $response);

        if ($requireAuth) {
            $this->authenticateRequest('bearer');
        }
    }

    /**
     * Send a JSON response using the PSR-aware response pipeline.
     *
     * @param mixed $data
     * @param int $statusCode
     * @param array $headers
     */
    protected function jsonResponse(mixed $data, int $statusCode = 200, array $headers = []): void
    {
        $payload = [
            'status'  => $statusCode,
            'message' => $this->statusPhrases[$statusCode] ?? null,
            'data'    => $data,
        ];

        // Prefer Response wrapper sendJson if present.
        if (isset($this->response) && method_exists($this->response, 'sendJson')) {
            // sendJson should handle status code
            $this->response->sendJson($payload, $statusCode);
            return;
        }

        // Otherwise use PSR response and attach headers.
        $psr = $this->response->getCoreResponse()->json($payload, $statusCode);
        foreach ($headers as $k => $v) {
            $psr = $psr->withHeader($k, (string)$v);
        }

        $this->emitPsrResponse($psr, true);
    }

    /**
     * Validate incoming JSON request body contains required fields.
     *
     * @param array $requiredFields
     * @return array
     */
    protected function validateJsonRequest(array $requiredFields): array
    {
        $bodyStream = $this->request->getPsrRequest()->getBody();
        if ($bodyStream->isSeekable()) {
            $bodyStream->rewind();
        }
        $raw = (string)$bodyStream;

        if ($raw === '') {
            $this->jsonErrorResponse('Missing JSON body', 400);
            // jsonErrorResponse will emit and exit.
        }

        try {
            $input = JsonHelper::decode($raw, true);
        } catch (Exception $e) {
            // decode will throw InvalidArgumentException; respond with 400
            $this->jsonErrorResponse('Invalid JSON payload', 400);
        }

        if (!is_array($input)) {
            $this->jsonErrorResponse('Invalid JSON payload', 400);
        }

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $input)) {
                $this->jsonErrorResponse("Missing required field: {$field}", 400);
            }
        }

        return $input;
    }

    /**
     * Authenticate API request using a chosen method.
     *
     * @param string $method
     * @param string|null $expectedToken
     */
    protected function authenticateRequest(string $method, ?string $expectedToken = null): void
    {
        switch (strtolower($method)) {
            case 'bearer':
                $this->authenticateBearerToken($expectedToken);
                break;
            case 'basic':
                $this->authenticateBasicAuth($expectedToken);
                break;
            case 'api_key':
                $this->authenticateApiKey($expectedToken);
                break;
            default:
                $this->jsonErrorResponse('Invalid or unsupported authentication method', 400);
        }
    }

    /**
     * Authenticate using Bearer token (Authorization: Bearer <token>).
     *
     * @param string|null $expectedToken optional expected token to compare
     */
    protected function authenticateBearerToken(?string $expectedToken = null): void
    {
        $authHeader = $this->request->getHeader('Authorization') ?? '';
        if ($authHeader === '') {
            $this->jsonErrorResponse('Unauthorized: Missing Authorization header', 401);
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            $this->jsonErrorResponse('Unauthorized: Invalid Authorization header', 401);
        }

        $token = trim(substr($authHeader, 7));
        if ($token === '') {
            $this->jsonErrorResponse('Unauthorized: Empty bearer token', 401);
        }

        $t = new TokenManager(Config::get('security.token_secret'));
        $validated = $t->validateBearerToken($token);

        if ($validated === null || ($expectedToken !== null && !SecurityHelper::constantTimeEquals($expectedToken, $token))) {
            $this->jsonErrorResponse('Unauthorized: Invalid Bearer Token', 401);
        }

        // attach validated subject to request attributes if Request supports set()
        if (method_exists($this->request, 'set')) {
            $this->request->set('auth_subject', $validated);
        } 
    }

    /**
     * Authenticate using Basic Authentication header.
     *
     * @param string|null $expected optional expected credential string (not used by default)
     */
    protected function authenticateBasicAuth(?string $expected = null): void
    {
        $authorizationHeader = $this->request->getHeader('Authorization') ?? '';
        if ($authorizationHeader === '' || !str_starts_with($authorizationHeader, 'Basic ')) {
            // Build PSR response with WWW-Authenticate header + 401
            $psr = $this->response->getCoreResponse()->withHeader('WWW-Authenticate', 'Basic realm="Access restricted"');
            $this->emitPsrResponse($psr->withStatus(401), true);
        }

        $base64 = trim(substr($authorizationHeader, 6));
        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            $this->jsonErrorResponse('Unauthorized: Invalid Base64 encoding', 401);
        }

        if (strpos($decoded, ':') === false) {
            $this->jsonErrorResponse('Unauthorized: Invalid credentials format', 401);
        }

        [$username, $password] = explode(':', $decoded, 2);

        if (!$this->isValidBasicAuthCredentials($username, $password)) {
            $psr = $this->response->getCoreResponse()->withHeader('WWW-Authenticate', 'Basic realm="Access restricted"');
            $this->emitPsrResponse($psr->withStatus(401), true);
        }

        if (method_exists($this->request, 'set')) {
            $this->request->set('auth_subject', $username);
        }
    }

    /**
     * Authenticate using API key (header or server env).
     *
     * @param string|null $expectedKey
     */
    protected function authenticateApiKey(?string $expectedKey = null): void
    {
        $apiKey = $this->request->getHeader('X-API-Key') ?? '';
        if ($apiKey === '') {
            $authHeader = $this->request->getHeader('Authorization') ?? '';
            if (str_starts_with($authHeader, 'ApiKey ')) {
                $apiKey = trim(substr($authHeader, 7));
            }
        }

        if ($apiKey === '') {
            $apiKey = $_SERVER['API_KEY'] ?? '';
        }

        if ($apiKey === '') {
            $this->jsonErrorResponse('Unauthorized: Missing API Key', 401);
        }

        $t = new TokenManager(Config::get('security.token_secret'));
        $validated = $t->validateAPIKey($apiKey);

        if ($validated === null || ($expectedKey !== null && !SecurityHelper::constantTimeEquals($expectedKey, $apiKey))) {
            $this->jsonErrorResponse('Unauthorized: Invalid API Key', 401);
        }

        if (method_exists($this->request, 'set')) {
            $this->request->set('auth_subject', $validated);
        }
    }

    /**
     * Concrete controllers must implement Basic Auth validation.
     *
     * @param string $username
     * @param string $password
     * @return bool
     */
    protected abstract function isValidBasicAuthCredentials(string $username, string $password): bool;
}
