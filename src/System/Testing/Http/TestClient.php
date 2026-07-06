<?php
declare(strict_types=1);

namespace System\Testing\Http;

use System\Core\Kernel;
use System\Session\SessionStore;

class TestClient
{
    private RequestFactory $factory;
    private array $defaultHeaders = [];
    private array $sessionData = [];

    public function __construct(
        private Kernel $kernel
    ) {
        $this->factory = new RequestFactory();
    }

    public function withHeader(string $key, string $value): self
    {
        $this->defaultHeaders[$key] = $value;
        return $this;
    }

    public function withSession(array $data): self
    {
        // Merge into current session
        foreach ($data as $k => $v) {
            SessionStore::set($k, $v);
        }
        return $this;
    }

    public function actingAs(int|string $userId, array $roles = [], string $name = 'Test User', string $email = 'test@example.com'): self
    {
        SessionStore::loginSuccess($userId, $email, $roles, $name);
        return $this;
    }
    
    public function withCsrf(): self
    {
        // If CSRF middleware is active, it checks for X-CSRF-TOKEN or _token input.
        // We can just inject the token into the header or session.
        // Or if we can simply disable CSRF for tests?
        // Requirement: "withCsrf()". implies we want to simulate valid CSRF.
        $token = \System\Security\Csrf::token(); // Generate if not exists
        $this->withHeader('X-CSRF-TOKEN', $token);
        return $this;
    }

    public function get(string $uri, array $query = []): TestResponse
    {
        if (!empty($query)) {
            $sep = str_contains($uri, '?') ? '&' : '?';
            $uri .= $sep . http_build_query($query);
        }
        return $this->request('GET', $uri);
    }

    public function post(string $uri, array $data = []): TestResponse
    {
        return $this->request('POST', $uri, $data);
    }

    public function put(string $uri, array $data = []): TestResponse
    {
        return $this->request('PUT', $uri, $data);
    }

    public function delete(string $uri, array $data = []): TestResponse
    {
        return $this->request('DELETE', $uri, $data);
    }

    public function json(string $method, string $uri, array $data = []): TestResponse
    {
        $headers = array_merge($this->defaultHeaders, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ]);
        return $this->request($method, $uri, $data, $headers);
    }

    public function request(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        // Merge headers
        $finalHeaders = array_merge($this->defaultHeaders, $headers);

        // Create PSR request
        $psrRequest = $this->factory->create($method, $uri, $data, $finalHeaders);

        // Wrap in System\Http\Request
        $sysRequest = new \System\Http\Request($psrRequest);

        // Create empty Response
        $sysResponse = new \System\Http\Response();

        // Dispatch via Kernel
        // Note: Kernel::handle expects generic Response ?? No, typed System\Http\Response
        $finalResponse = $this->kernel->handle($sysRequest, $sysResponse);

        return new TestResponse($finalResponse);
    }
}
