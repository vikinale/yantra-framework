<?php
declare(strict_types=1);

namespace System\Services\Email\Transport;

use System\Services\Email\Contracts\TransportInterface;
use System\Services\Email\EmailMessage;
use System\Services\Email\Exceptions\TransportException;
use System\Services\Email\Mime\MimeBuilder;
use System\Services\Email\TransportResult;

/**
 * Minimal SMTP transport with optional STARTTLS and AUTH (LOGIN/PLAIN).
 *
 * Notes:
 * - Designed for production use in controlled environments.
 * - For high-volume sending, prefer an HTTP API transport (SES/Mailgun/SendGrid) and
 *   delegate deliverability concerns to the provider.
 */
final class SmtpTransport implements TransportInterface
{
    public function __construct(
        private string $host,
        private int $port = 587,
        private ?string $username = null,
        private ?string $password = null,
        private bool $useStartTls = true,
        private int $timeoutSeconds = 15,
        private bool $allowSelfSignedTls = false,
        /** @var null|callable */
        private $debugLogger = null, // fn(string $line):void
    ) {}

    public function send(EmailMessage $message): TransportResult
    {
        $message->requireBasicValidity();

        $mime = (new MimeBuilder())->build($message);
        $recipients = $message->allRecipients();

        $provider = 'smtp';
        $debug = [];

        $start = microtime(true);
        $sock = $this->connect();
        try {
            $this->expect($sock, 220, $debug);

            $local = gethostname() ?: 'localhost';
            $this->write($sock, "EHLO {$local}\r\n", $debug);
            $ehlo = $this->readMultiline($sock, $debug);
            $this->assertCode($ehlo['code'], 250, $debug);

            $cap = strtoupper($ehlo['text']);
            if ($this->useStartTls && str_contains($cap, 'STARTTLS')) {
                $this->write($sock, "STARTTLS\r\n", $debug);
                $this->expect($sock, 220, $debug);

                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                $ctx = stream_context_get_options($sock);
                // stream_socket_enable_crypto uses existing socket context; set verify flags at connect time (done)
                if (!@stream_socket_enable_crypto($sock, true, $crypto)) {
                    throw TransportException::wrap('Failed to enable STARTTLS');
                }

                // EHLO again after TLS
                $this->write($sock, "EHLO {$local}\r\n", $debug);
                $ehlo = $this->readMultiline($sock, $debug);
                $this->assertCode($ehlo['code'], 250, $debug);
                $cap = strtoupper($ehlo['text']);
            }

            if ($this->username !== null && $this->password !== null) {
                $this->authenticate($sock, $cap, $debug);
            }

            $from = $message->from->email;
            $this->write($sock, "MAIL FROM:<{$from}>\r\n", $debug);
            $this->expect($sock, 250, $debug);

            foreach ($recipients as $rcpt) {
                $this->write($sock, "RCPT TO:<{$rcpt->email}>\r\n", $debug);
                $resp = $this->readLine($sock, $debug);
                // Accept 250 OK or 251/252 forwarding responses.
                if (!in_array($resp['code'], [250, 251, 252], true)) {
                    throw TransportException::wrap("RCPT TO rejected ({$rcpt->email}): {$resp['raw']}");
                }
            }

            $this->write($sock, "DATA\r\n", $debug);
            $this->expect($sock, 354, $debug);

            // Dot-stuffing
            $data = preg_replace('/\r\n\./', "\r\n..", $mime) ?? $mime;
            $this->write($sock, $data . "\r\n.\r\n", $debug);
            $final = $this->readLine($sock, $debug);
            if ($final['code'] !== 250) {
                throw TransportException::wrap("SMTP DATA rejected: {$final['raw']}");
            }

            $this->write($sock, "QUIT\r\n", $debug);
            $this->readLine($sock, $debug);

            $elapsedMs = (int)round((microtime(true) - $start) * 1000);

            return new TransportResult(
                ok: true,
                messageId: null,
                statusCode: 250,
                provider: $provider,
                debug: ['elapsed_ms' => $elapsedMs, 'transcript' => $debug]
            );
        } catch (\Throwable $e) {
            if (is_resource($sock)) {
                @fwrite($sock, "RSET\r\n");
                @fwrite($sock, "QUIT\r\n");
            }
            throw TransportException::wrap('SMTP send failed: ' . $e->getMessage(), $e);
        } finally {
            if (is_resource($sock)) @fclose($sock);
        }
    }

    private function connect()
    {
        $scheme = 'tcp';
        $remote = "{$scheme}://{$this->host}:{$this->port}";

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => !$this->allowSelfSignedTls,
                'verify_peer_name' => !$this->allowSelfSignedTls,
                'allow_self_signed' => $this->allowSelfSignedTls,
            ],
        ]);

        $sock = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $ctx
        );

        if (!$sock) {
            throw TransportException::wrap("SMTP connect failed ({$errno}): {$errstr}");
        }

        stream_set_timeout($sock, $this->timeoutSeconds);
        return $sock;
    }

    private function authenticate($sock, string $capabilities, array &$debug): void
    {
        // Prefer AUTH PLAIN if advertised; else fallback to LOGIN.
        $supportsPlain = str_contains($capabilities, 'AUTH') && str_contains($capabilities, 'PLAIN');
        $supportsLogin = str_contains($capabilities, 'AUTH') && str_contains($capabilities, 'LOGIN');

        if ($supportsPlain) {
            $auth = base64_encode("\0{$this->username}\0{$this->password}");
            $this->write($sock, "AUTH PLAIN {$auth}\r\n", $debug, 'AUTH PLAIN [REDACTED]');
            $resp = $this->readLine($sock, $debug);
            if ($resp['code'] !== 235) {
                throw TransportException::wrap("AUTH PLAIN failed: {$resp['raw']}");
            }
            return;
        }

        if ($supportsLogin) {
            $this->write($sock, "AUTH LOGIN\r\n", $debug);
            $this->expect($sock, 334, $debug);
            $this->write($sock, base64_encode($this->username ?? '') . "\r\n", $debug, '[REDACTED]');
            $this->expect($sock, 334, $debug);
            $this->write($sock, base64_encode($this->password ?? '') . "\r\n", $debug, '[REDACTED]');
            $resp = $this->readLine($sock, $debug);
            if ($resp['code'] !== 235) {
                throw TransportException::wrap("AUTH LOGIN failed: {$resp['raw']}");
            }
            return;
        }

        // Try AUTH LOGIN anyway; some servers don't advertise fully.
        $this->write($sock, "AUTH LOGIN\r\n", $debug);
        $resp = $this->readLine($sock, $debug);
        if ($resp['code'] !== 334) {
            throw TransportException::wrap('SMTP server does not support AUTH (or rejected auth).');
        }
        $this->write($sock, base64_encode($this->username ?? '') . "\r\n", $debug, '[REDACTED]');
        $this->expect($sock, 334, $debug);
        $this->write($sock, base64_encode($this->password ?? '') . "\r\n", $debug, '[REDACTED]');
        $this->expect($sock, 235, $debug);
    }

    private function write($sock, string $data, array &$debug, ?string $redacted = null): void
    {
        // For AUTH-related lines pass $redacted so credentials never reach the log/transcript
        // (base64 is trivially decodable). The real $data is still sent to the socket.
        $transcript = $redacted ?? rtrim($data, "\r\n");
        $this->log('C: ' . $transcript);
        $debug[] = 'C: ' . $transcript;
        $n = @fwrite($sock, $data);
        if ($n === false) throw TransportException::wrap('SMTP write failed');
    }

    private function readLine($sock, array &$debug): array
    {
        $line = @fgets($sock, 8192);
        if ($line === false) throw TransportException::wrap('SMTP read failed');
        $this->log('S: ' . trim($line));
        $debug[] = 'S: ' . rtrim($line, "\r\n");
        $code = (int)substr($line, 0, 3);
        return ['code' => $code, 'raw' => $line, 'text' => substr($line, 4)];
    }

    private function readMultiline($sock, array &$debug): array
    {
        $lines = [];
        $code = null;
        while (true) {
            $resp = $this->readLine($sock, $debug);
            $code ??= $resp['code'];
            $lines[] = rtrim($resp['raw'], "\r\n");
            // multiline response has hyphen after code (e.g. 250-)
            if (isset($resp['raw'][3]) && $resp['raw'][3] === ' ') break;
        }
        return ['code' => $code ?? 0, 'text' => implode("\n", $lines)];
    }

    private function expect($sock, int $expected, array &$debug): void
    {
        $resp = $this->readLine($sock, $debug);
        $this->assertCode($resp['code'], $expected, $debug, $resp['raw']);
    }

    private function assertCode(int $actual, int $expected, array &$debug, string $raw = ''): void
    {
        if ($actual !== $expected) {
            throw TransportException::wrap("Unexpected SMTP code {$actual}, expected {$expected}. {$raw}");
        }
    }

    private function log(string $line): void
    {
        if ($this->debugLogger) {
            ($this->debugLogger)($line);
        }
    }
}
