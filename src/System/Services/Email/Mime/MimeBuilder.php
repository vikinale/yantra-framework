<?php
declare(strict_types=1);

namespace System\Services\Email\Mime;

use System\Services\Email\Attachment;
use System\Services\Email\EmailMessage;

/**
 * Builds RFC 5322 / MIME message for SMTP transport.
 * Supports:
 * - text only
 * - html only
 * - multipart/alternative (text+html)
 * - attachments (multipart/mixed with nested multipart/alternative)
 */
final class MimeBuilder
{
    public function build(EmailMessage $m): string
    {
        $m->requireBasicValidity();

        $headers = [];
        $headers[] = 'From: ' . $m->from->format();
        if (!empty($m->to)) $headers[] = 'To: ' . $this->joinAddresses($m->to);
        if (!empty($m->cc)) $headers[] = 'Cc: ' . $this->joinAddresses($m->cc);
        if (!empty($m->replyTo)) $headers[] = 'Reply-To: ' . $this->joinAddresses($m->replyTo);

        $headers[] = 'Subject: ' . $this->encodeHeader($this->stripCrlf($m->subject ?? ''));
        $headers[] = 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000';
        $headers[] = 'MIME-Version: 1.0';

        // Custom headers
        foreach ($m->headers as $k => $v) {
            // Strip CR/LF and validate the key is a legal header token to prevent header injection.
            $k = trim($this->stripCrlf((string)$k));
            if ($k === '' || !preg_match('/^[A-Za-z0-9-]+$/', $k)) continue;
            // Strip CR/LF from the raw value BEFORE encoding so injected headers cannot break out;
            // legitimate line-folding is still produced by encodeHeader() afterwards.
            $headers[] = $k . ': ' . $this->encodeHeader($this->stripCrlf((string)$v));
        }

        $body = '';
        if (!empty($m->attachments)) {
            $mixed = $this->boundary();
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixed . '"';

            $body .= $this->partPreamble($mixed);
            $body .= $this->buildBodyPart($m, $mixed);

            foreach ($m->attachments as $a) {
                $body .= $this->buildAttachmentPart($mixed, $a);
            }
            $body .= "--{$mixed}--\r\n";
        } else {
            // no attachments
            [$contentType, $payload] = $this->buildTextOrAlternative($m);
            $headers[] = 'Content-Type: ' . $contentType;
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $body = $payload;
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /** @param \Services\Email\Address[] $addresses */
    private function joinAddresses(array $addresses): string
    {
        return implode(', ', array_map(fn($a) => $a->format(), $addresses));
    }

    private function stripCrlf(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[\x80-\xFF]/', $value) && function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        }
        return $value;
    }

    private function boundary(): string
    {
        return '=_yantra_' . bin2hex(random_bytes(12));
    }

    private function partPreamble(string $boundary): string
    {
        return "This is a multi-part message in MIME format.\r\n\r\n" .
            "--{$boundary}\r\n";
    }

    private function buildBodyPart(EmailMessage $m, string $mixedBoundary): string
    {
        // If both text+html, make multipart/alternative nested inside mixed.
        if ($m->textBody && $m->htmlBody) {
            $alt = $this->boundary();
            $out = '';
            $out .= 'Content-Type: multipart/alternative; boundary="' . $alt . "\"\r\n\r\n";
            // text
            $out .= "--{$alt}\r\n";
            $out .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $out .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $out .= $this->normalizeEol($m->textBody) . "\r\n\r\n";
            // html
            $out .= "--{$alt}\r\n";
            $out .= "Content-Type: text/html; charset=UTF-8\r\n";
            $out .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $out .= $this->normalizeEol($m->htmlBody) . "\r\n\r\n";
            $out .= "--{$alt}--\r\n";

            // End body part and start next mixed part
            return $out . "\r\n--{$mixedBoundary}\r\n";
        }

        [$contentType, $payload] = $this->buildTextOrAlternative($m);
        $out = '';
        $out .= "Content-Type: {$contentType}\r\n";
        $out .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $out .= $payload . "\r\n\r\n";
        $out .= "--{$mixedBoundary}\r\n";
        return $out;
    }

    private function buildAttachmentPart(string $boundary, Attachment $a): string
    {
        $filename = str_replace(['"', "\r", "\n"], ['\"', '', ''], $a->filename);
        $out = '';
        $out .= "Content-Type: {$a->contentType}; name=\"{$filename}\"\r\n";
        $out .= "Content-Transfer-Encoding: base64\r\n";
        $out .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
        $out .= chunk_split($a->contentBase64, 76, "\r\n") . "\r\n";
        $out .= "--{$boundary}\r\n";
        return $out;
    }

    private function buildTextOrAlternative(EmailMessage $m): array
    {
        if ($m->htmlBody && !$m->textBody) {
            return ['text/html; charset=UTF-8', $this->normalizeEol($m->htmlBody)];
        }
        // text-only or prefer text when both missing html (validity ensured)
        return ['text/plain; charset=UTF-8', $this->normalizeEol($m->textBody ?? '')];
    }

    private function normalizeEol(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        return str_replace("\n", "\r\n", $s);
    }
}
