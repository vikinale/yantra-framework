<?php
declare(strict_types=1);

namespace System\Services\Webhooks\Delivery;

use System\Services\Webhooks\Contracts\TransportInterface;

/**
 * Default HTTP transport using cURL.
 * No external dependencies.
 */
final class CurlTransport implements TransportInterface
{
    public function send(DeliveryRequest $request): DeliveryResult
    {
        $start = microtime(true);

        $ch = curl_init($request->endpoint->url);
        if ($ch === false) {
            return DeliveryResult::networkError('curl_init_failed', 0);
        }

        $headers = [];
        foreach ($request->headers as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }

        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request->body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$respHeaders) {
                $len = strlen($headerLine);
                $headerLine = trim($headerLine);
                if ($headerLine === '' || !str_contains($headerLine, ':')) return $len;
                [$name, $value] = explode(':', $headerLine, 2);
                $respHeaders[trim($name)] = trim($value);
                return $len;
            },
            CURLOPT_TIMEOUT => $request->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $request->connectTimeoutSeconds,
        ]);

        $body = curl_exec($ch);
        $errNo = curl_errno($ch);
        $err = $errNo ? curl_error($ch) : null;
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $durationMs = (int)round((microtime(true) - $start) * 1000);

        if ($body === false || $errNo) {
            return DeliveryResult::networkError($err ?: 'curl_error', $durationMs);
        }

        $respBody = (string)$body;
        $ok = ($status >= 200 && $status <= 299);

        return new DeliveryResult($ok, $status, $respBody, $respHeaders, null, $durationMs);
    }
}
