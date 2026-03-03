<?php
declare(strict_types=1);

namespace System\Services\Email;

final class EmailMessage
{
    /** @var Address[] */
    public array $to = [];
    /** @var Address[] */
    public array $cc = [];
    /** @var Address[] */
    public array $bcc = [];
    /** @var Address[] */
    public array $replyTo = [];

    public ?Address $from = null;
    public ?string $subject = null;

    public ?string $textBody = null;
    public ?string $htmlBody = null;

    /** @var array<string,string> */
    public array $headers = [];

    /** @var Attachment[] */
    public array $attachments = [];

    /**
     * Provider-agnostic metadata bag.
     *
     * Conventions used by this toolkit:
     * - $meta['categories'] => string[]
     * - $meta['custom_args'] => array<string,string>
     * - $meta['tags'] => string[]
     * - $meta['sendgrid'] => array{
     *     template_id?: string,
     *     dynamic_template_data?: array<string,mixed>,
     *     personalizations?: array<int,array<string,mixed>>,
     *     asm?: array<string,mixed>,
     *     tracking_settings?: array<string,mixed>,
     *     mail_settings?: array<string,mixed>,
     *     sandbox_mode?: bool,
     * }
     */
    public array $meta = [];

    public function requireBasicValidity(): void
    {
        if ($this->from === null) throw new \InvalidArgumentException('EmailMessage.from is required');
        if (empty($this->to) && empty($this->cc) && empty($this->bcc)) {
            throw new \InvalidArgumentException('At least one recipient (to/cc/bcc) is required');
        }

        // Some providers (e.g., SendGrid) allow sending using a template_id with no inline body.
        $sendgrid = $this->meta['sendgrid'] ?? null;
        $hasProviderTemplate = is_array($sendgrid) && !empty($sendgrid['template_id']);

        if (!$hasProviderTemplate && ($this->textBody === null || $this->textBody === '') && ($this->htmlBody === null || $this->htmlBody === '')) {
            throw new \InvalidArgumentException('Either textBody or htmlBody is required (unless using a provider template_id)');
        }
        if ($this->subject === null) $this->subject = '';
    }

    /** @return Address[] */
    public function allRecipients(): array
    {
        return array_values(array_merge($this->to, $this->cc, $this->bcc));
    }
}
