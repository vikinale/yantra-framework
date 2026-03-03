<?php
declare(strict_types=1);

namespace System\Services\Queue\Contracts;

interface SerializerInterface
{
    /** @param array<string,mixed> $payload */
    public function encode(array $payload): string;

    /** @return array<string,mixed> */
    public function decode(string $payload): array;
}
