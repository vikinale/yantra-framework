<?php
declare(strict_types=1);

namespace System\Services\Reporting;

use DateTimeImmutable;

/**
 * Lightweight context object passed to report runners.
 *
 * The Reporting Service is DB/UI/route-agnostic. The application can attach
 * any dependencies (e.g., repositories, API clients) via attributes.
 */
final class ReportContext
{
    private string $timezone;
    private string $locale;
    private mixed $actor;

    /** @var array<string, mixed> */
    private array $attributes;

    private DateTimeImmutable $now;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        string $timezone = 'UTC',
        string $locale = 'en',
        mixed $actor = null,
        array $attributes = [],
        ?DateTimeImmutable $now = null
    ) {
        $this->timezone = $timezone;
        $this->locale = $locale;
        $this->actor = $actor;
        $this->attributes = $attributes;
        $this->now = $now ?? new DateTimeImmutable('now');
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function actor(): mixed
    {
        return $this->actor;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function with(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;
        return $clone;
    }
}
