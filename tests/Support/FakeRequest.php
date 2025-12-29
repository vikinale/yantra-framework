<?php
declare(strict_types=1);

namespace Tests\Support;

final class FakeRequest
{
    /** @var array<string,mixed> */
    private array $query = [];

    /** @var array<string,mixed> */
    private array $body = [];

    /** @var array<string,string> */
    private array $headers = [];

    private string $method = 'GET';
    private string $path = '/';

    public function withMethod(string $method): self
    {
        $c = clone $this;
        $c->method = strtoupper($method);
        return $c;
    }

    public function withPath(string $path): self
    {
        $c = clone $this;
        $c->path = $path;
        return $c;
    }

    /** @param array<string,mixed> $query */
    public function withQuery(array $query): self
    {
        $c = clone $this;
        $c->query = $query;
        return $c;
    }

    /** @param array<string,mixed> $body */
    public function withBody(array $body): self
    {
        $c = clone $this;
        $c->body = $body;
        return $c;
    }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): self
    {
        $c = clone $this;
        $c->headers = $headers;
        return $c;
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }

    /** @return array<string,mixed> */
    public function query(): array { return $this->query; }

    /** @return array<string,mixed> */
    public function body(): array { return $this->body; }

    /** @return array<string,string> */
    public function headers(): array { return $this->headers; }

    /** @return array<string,mixed> */
    public function all(): array
    {
        // emulate typical Request::all behavior (query + body)
        return array_merge($this->query, $this->body);
    }
}
