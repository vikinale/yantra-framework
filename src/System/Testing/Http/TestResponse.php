<?php
declare(strict_types=1);

namespace System\Testing\Http;

use RuntimeException;

class TestResponse
{
    public function __construct(
        private \System\Http\Response $baseResponse
    ) {}

    public function assertStatus(int $status): self
    {
        $actual = $this->baseResponse->getStatusCode();
        if ($actual !== $status) {
            throw new RuntimeException(
                "Expected status {$status} but received {$actual}.\nBody: " . substr($this->body(), 0, 500)
            );
        }
        return $this;
    }

    public function assertHeader(string $name, mixed $value): self
    {
        $actual = $this->baseResponse->getHeaderLine($name);
        if ($actual !== (string)$value) {
            throw new RuntimeException("Expected header {$name} to be '{$value}', got '{$actual}'");
        }
        return $this;
    }

    public function assertJson(array $subset): self
    {
        $data = $this->json();
        $this->assertArraySubset($subset, $data);
        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        $data = $this->json();
        $actual = $this->dataGet($data, $path);
        
        if ($actual !== $expected) {
            throw new RuntimeException(
                "JSON path '{$path}' does not match.\nExpected: " . json_encode($expected) . "\nActual: " . json_encode($actual)
            );
        }
        return $this;
    }

    public function assertRedirect(string $uri = null): self
    {
        $status = $this->status();
        if (!in_array($status, [301, 302, 303, 307, 308])) {
            throw new RuntimeException("Expected redirect status, got {$status}");
        }
        
        if ($uri !== null) {
            $this->assertHeader('Location', $uri);
        }
        return $this;
    }

    public function json(): array
    {
        $body = $this->body();
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON response: " . json_last_error_msg());
        }
        return $data;
    }

    public function body(): string
    {
        $stream = $this->baseResponse->getBody();
        $stream->rewind();
        return $stream->getContents();
    }

    public function status(): int
    {
        return $this->baseResponse->getStatusCode();
    }

    public function headers(): array
    {
        return $this->baseResponse->getHeaders();
    }

    // --- Helpers ---

    private function assertArraySubset(array $subset, array $array): void
    {
        foreach ($subset as $key => $value) {
            if (!array_key_exists($key, $array)) {
                throw new RuntimeException("Key '{$key}' missing from JSON response.");
            }
            
            if (is_array($value) && is_array($array[$key])) {
                $this->assertArraySubset($value, $array[$key]);
            } elseif ($value !== $array[$key]) {
                throw new RuntimeException("Value mismatch for key '{$key}'. Expected " . json_encode($value) . ", got " . json_encode($array[$key]));
            }
        }
    }

    private function dataGet(mixed $target, string|array $key, mixed $default = null): mixed
    {
        if (is_null($key)) return $target;
        
        $key = is_array($key) ? $key : explode('.', $key);
        
        foreach ($key as $i => $segment) {
            unset($key[$i]);
            if (is_null($segment)) return $target;
            if (is_array($target)) {
                if (array_key_exists($segment, $target)) {
                    $target = $target[$segment];
                } else {
                    return $default;
                }
            } else {
                return $default;
            }
        }
        return $target;
    }
}
