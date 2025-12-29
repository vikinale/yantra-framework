<?php
declare(strict_types=1);

namespace Tests\Support;

final class FakeResponse
{
    private int $status = 200;

    /** @var array<string,string> */
    private array $headers = [];

    private ?string $body = null;

    /** @var array<string,mixed>|null */
    private ?array $json = null;

    public function withStatus(int $status): self
    {
        $c = clone $this;
        $c->status = $status;
        return $c;
    }

    public function status(): int { return $this->status; }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): self
    {
        $c = clone $this;
        $c->headers = $headers;
        return $c;
    }

    /** @return array<string,string> */
    public function headers(): array { return $this->headers; }

    public function html(string $html): self
    {
        $c = clone $this;
        $c->body = $html;
        $c->json = null;
        $c->headers['Content-Type'] = 'text/html; charset=utf-8';
        return $c;
    }

    /** @param array<string,mixed> $data */
    public function json(array $data): self
    {
        $c = clone $this;
        $c->json = $data;
        $c->body = null;
        $c->headers['Content-Type'] = 'application/json; charset=utf-8';
        return $c;
    }

    public function body(): ?string { return $this->body; }

    /** @return array<string,mixed>|null */
    public function jsonBody(): ?array { return $this->json; }
}
