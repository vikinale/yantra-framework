<?php
declare(strict_types=1);

namespace System\Services\Email\Transport;

use System\Services\\Email\Contracts\TransportInterface;
use System\Services\\Email\EmailMessage;
use System\Services\\Email\Exceptions\TransportException;
use System\Services\\Email\TransportResult;

/**
 * Generic HTTP API transport.
 * The app provides a payload mapper closure that converts EmailMessage -> array for provider API.
 *
 * Example providers: SES, Mailgun, SendGrid, Postmark, etc.
 */
final class ApiTransport implements TransportInterface
{
    /**
     * @param callable $payloadMapper fn(EmailMessage $message): array{method?:string, path?:string, headers?:array, body:array|string}
     */
    public function __construct(
        private string $baseUrl,
        private array $defaultHeaders = [],
        private $payloadMapper = null,
        private int $timeoutSeconds = 15
    ) {
        if ($this->payloadMapper === null) {
            $this->payloadMapper = fn(EmailMessage $m) => ['body' => $this->defaultJsonPayload($m)];
        }
    }

    public function send(EmailMessage $message): TransportResult
    {
        $message->requireBasicValidity();

        $map = ($this->payloadMapper)($message);
        $method = strtoupper($map['method'] ?? 'POST');
        $path = $map['path'] ?? '';
        $headers = array_merge($this->defaultHeaders, $map['headers'] ?? []);

        $body = $map['body'] ?? null;
        if ($body === null) {
            throw TransportException::wrap('API transport payload mapper must return a body.');
        }

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim((string)$path, '/');

        [$status, $respHeaders, $respBody] = $this->curl($method, $url, $headers, $body);

        $ok = $status >= 200 && $status < 300;
        if (!$ok) {
            throw TransportException::wrap("Email API failed with status {$status}: {$respBody}");
        }

        // MessageId extraction is provider-specific; keep raw response.
        return new TransportResult(
            ok: true,
            messageId: null,
            statusCode: $status,
            provider: 'http-api',
            debug: ['response_headers' => $respHeaders, 'response_body' => $respBody]
        );
    }

    private function defaultJsonPayload(EmailMessage $m): array
    {
        return [
            'from' => $m->from?->email,
            'to' => array_map(fn($a) => $a->email, $m->to),
            'cc' => array_map(fn($a) => $a->email, $m->cc),
            'bcc' => array_map(fn($a) => $a->email, $m->bcc),
            'subject' => $m->subject,
            'text' => $m->textBody,
            'html' => $m->htmlBody,
        ];
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
                throw TransportException::wrap('Failed to JSON encode API payload');
            }
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
        }

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

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
