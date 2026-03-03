<?php
declare(strict_types=1);

namespace System\Services\Queue\Serializers;

use System\Services\\Queue\Contracts\SerializerInterface;

final class JsonSerializer implements SerializerInterface
{
    public function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('JSON encode failed');
        return $json;
    }

    public function decode(string $payload): array
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) throw new \RuntimeException('JSON decode failed');
        return $data;
    }
}
