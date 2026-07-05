<?php
declare(strict_types=1);

namespace System\Services\Email\Queue;

use System\Services\Email\Address;
use System\Services\Email\Attachment;
use System\Services\Email\EmailMessage;

final class SendEmailPayload
{
    public function __construct(
        public array $from,
        public array $to,
        public array $cc,
        public array $bcc,
        public array $replyTo,
        public string $subject,
        public ?string $textBody,
        public ?string $htmlBody,
        public array $headers,
        public array $attachments,
        public array $meta = []
    ) {}

    public static function fromMessage(EmailMessage $m): self
    {
        $m->requireBasicValidity();
        return new self(
            from: ['email' => $m->from->email, 'name' => $m->from->name],
            to: array_map(fn($a) => ['email' => $a->email, 'name' => $a->name], $m->to),
            cc: array_map(fn($a) => ['email' => $a->email, 'name' => $a->name], $m->cc),
            bcc: array_map(fn($a) => ['email' => $a->email, 'name' => $a->name], $m->bcc),
            replyTo: array_map(fn($a) => ['email' => $a->email, 'name' => $a->name], $m->replyTo),
            subject: (string)$m->subject,
            textBody: $m->textBody,
            htmlBody: $m->htmlBody,
            headers: $m->headers,
            attachments: array_map(fn(Attachment $a) => [
                'filename' => $a->filename,
                'contentType' => $a->contentType,
                'contentBase64' => $a->contentBase64,
            ], $m->attachments),
            meta: $m->meta
        );
    }

    public function toMessage(): EmailMessage
    {
        $m = new EmailMessage();
        $m->from = new Address($this->from['email'], $this->from['name'] ?? null);
        $m->to = array_map(fn($a) => new Address($a['email'], $a['name'] ?? null), $this->to);
        $m->cc = array_map(fn($a) => new Address($a['email'], $a['name'] ?? null), $this->cc);
        $m->bcc = array_map(fn($a) => new Address($a['email'], $a['name'] ?? null), $this->bcc);
        $m->replyTo = array_map(fn($a) => new Address($a['email'], $a['name'] ?? null), $this->replyTo);
        $m->subject = $this->subject;
        $m->textBody = $this->textBody;
        $m->htmlBody = $this->htmlBody;
        $m->headers = $this->headers;
        $m->meta = $this->meta;

        $m->attachments = array_map(function($a) {
            return \Services\Email\Attachment::fromBase64($a['filename'], $a['contentBase64'], $a['contentType']);
        }, $this->attachments);

        return $m;
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'replyTo' => $this->replyTo,
            'subject' => $this->subject,
            'textBody' => $this->textBody,
            'htmlBody' => $this->htmlBody,
            'headers' => $this->headers,
            'attachments' => $this->attachments,
            'meta' => $this->meta,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            from: is_array($a['from'] ?? null) ? $a['from'] : [],
            to: is_array($a['to'] ?? null) ? $a['to'] : [],
            cc: is_array($a['cc'] ?? null) ? $a['cc'] : [],
            bcc: is_array($a['bcc'] ?? null) ? $a['bcc'] : [],
            replyTo: is_array($a['replyTo'] ?? null) ? $a['replyTo'] : [],
            subject: (string)($a['subject'] ?? ''),
            textBody: isset($a['textBody']) ? (string)$a['textBody'] : null,
            htmlBody: isset($a['htmlBody']) ? (string)$a['htmlBody'] : null,
            headers: is_array($a['headers'] ?? null) ? $a['headers'] : [],
            attachments: is_array($a['attachments'] ?? null) ? $a['attachments'] : [],
            meta: is_array($a['meta'] ?? null) ? $a['meta'] : [],
        );
    }
}
