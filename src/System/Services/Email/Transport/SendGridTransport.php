<?php
declare(strict_types=1);

namespace System\Services\Email\Transport;

use System\Services\Email\Contracts\TransportInterface;
use System\Services\Email\EmailMessage;
use System\Services\Email\Exceptions\TransportException;
use System\Services\Email\TransportResult;

/**
 * SendGrid v3 Mail Send transport.
 *
 * - No DB, no UI, no routing: the app wires this transport and manages configs.
 * - Uses SendGrid's /v3/mail/send endpoint.
 */
final class SendGridTransport implements TransportInterface
{
    public function __construct(
        private string $apiKey,
        private ?string $baseUrl = null,
        private ?SendGridPayloadBuilder $builder = null,
        private int $timeoutSeconds = 15
    ) {
        $this->baseUrl = $this->baseUrl ?? 'https://api.sendgrid.com/v3';
        $this->builder = $this->builder ?? new SendGridPayloadBuilder();
    }

    public function send(EmailMessage $message): TransportResult
    {
        $message->requireBasicValidity();

        $payload = $this->builder->build($message);

        $url = rtrim((string)$this->baseUrl, '/') . '/mail/send';

        [$status, $respHeaders, $respBody] = $this->curl(
            method: 'POST',
            url: $url,
            headers: [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            body: $payload
        );

        $ok = $status >= 200 && $status < 300;
        if (!$ok) {
            throw TransportException::wrap("SendGrid Mail Send failed with status {$status}: {$respBody}");
        }

        // SendGrid returns an X-Message-ID response header for correlation with Event Webhook.
        $messageId = $respHeaders['x-message-id'] ?? null;

        return new TransportResult(
            ok: true,
            messageId: $messageId,
            statusCode: $status,
            provider: 'sendgrid',
            debug: ['response_headers' => $respHeaders, 'response_body' => $respBody]
        );
    }

    private function curl(string $method, string $url, array $headers, $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw TransportException::wrap('Failed to init curl');
        }

        $outHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$outHeaders) {
                $len = strlen($header);
                $header = trim($header);
                if ($header === '' || !str_contains($header, ':')) return $len;
                [$k, $v] = explode(':', $header, 2);
                $outHeaders[strtolower(trim($k))] = trim($v);
                return $len;
            },
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $payload = $body;
        if (is_array($body)) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                throw TransportException::wrap('Failed to JSON encode SendGrid payload');
            }
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }
        if (!empty($headerLines)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

        $respBody = curl_exec($ch);
        if ($respBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw TransportException::wrap('Curl error: ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, $outHeaders, (string)$respBody];
    }
}
