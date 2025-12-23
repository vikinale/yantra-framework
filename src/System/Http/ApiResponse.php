<?php
declare(strict_types=1);

namespace System\Http;

final class ApiResponse
{
    public static function json(Response $res, mixed $data, int $status = 200, array $headers = []): void
    {
        $res->withStatus($status);

        self::setHeader($res, 'Content-Type', 'application/json; charset=utf-8');
        self::setHeader($res, 'Cache-Control', 'no-store');

        foreach ($headers as $k => $v) {
            if (is_string($k) && $k !== '') {
                self::setHeader($res, $k, (string)$v);
            }
        }

        echo json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    public static function error(Response $res, string $code, string $message, int $status = 400, array $meta = []): void
    {
        $payload = [
            'error' => $code,
            'message' => $message,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        self::json($res, $payload, $status);
    }

    public static function noContent(Response $res, int $status = 204, array $headers = []): void
    {
        $res->withStatus($status);
        foreach ($headers as $k => $v) {
            self::setHeader($res, (string)$k, (string)$v);
        }
        // No body
    }

    private static function setHeader(Response $res, string $name, string $value): void
    {
        if (method_exists($res, 'withHeader')) {
            $res->withHeader($name, $value);
            return;
        }
        header($name . ': ' . $value, true);
    }
}
