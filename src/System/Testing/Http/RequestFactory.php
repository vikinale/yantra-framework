<?php
declare(strict_types=1);

namespace System\Testing\Http;

use System\Http\Request;

class RequestFactory
{
    public function create(string $method, string $uri, array $data = [], array $headers = []): Request
    {
        // Reset globals
        $_SERVER = [];
        $_GET = [];
        $_POST = [];

        $_SERVER['REQUEST_METHOD'] = strtoupper($method);
        $_SERVER['REQUEST_URI'] = $uri;
        
        $parts = parse_url($uri);
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        
        if (isset($parts['query'])) {
            parse_str($parts['query'], $_GET);
        }

        foreach ($headers as $k => $v) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
            $_SERVER[$key] = (string)$v;
            if (strcasecmp($k, 'Content-Type') === 0) {
                $_SERVER['CONTENT_TYPE'] = (string)$v;
            }
        }

        if (!empty($data)) {
            if ($this->isJson($headers)) {
                // In a real native test environment, overriding php://input is tricky.
                // For unit tests, we can inject a mocked property via Reflection into Request,
                // or assume tests will pass it via POST for simplicity.
                // We'll use Reflection to set cachedInputs directly.
                $req = new Request();
                $ref = new \ReflectionClass(Request::class);
                $prop = $ref->getProperty('cachedInputs');
                $prop->setAccessible(true);
                $prop->setValue($req, $data);
                return $req;
            } else {
                $_POST = $data;
            }
        }

        return new Request();
    }

    private function isJson(array $headers): bool
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'Content-Type') === 0 && str_contains(strtolower((string)$v), 'json')) {
                return true;
            }
        }
        return false;
    }
}
