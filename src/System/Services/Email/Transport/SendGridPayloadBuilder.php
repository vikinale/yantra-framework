<?php
declare(strict_types=1);

namespace System\Services\Email\Transport;

use System\Services\Email\Attachment;
use System\Services\Email\EmailMessage;

/**
 * Builds a SendGrid v3 Mail Send payload from the toolkit's EmailMessage.
 *
 * Ref: SendGrid Mail Send API schema (personalizations, dynamic_template_data, template_id, headers, attachments).
 */
final class SendGridPayloadBuilder
{
    public function build(EmailMessage $m): array
    {
        $sendgrid = $m->meta['sendgrid'] ?? [];
        if (!is_array($sendgrid)) {
            $sendgrid = [];
        }

        // Allow the application to fully override the payload if needed.
        if (isset($sendgrid['payload']) && is_array($sendgrid['payload'])) {
            return $sendgrid['payload'];
        }

        $templateId = isset($sendgrid['template_id']) ? (string)$sendgrid['template_id'] : null;
        $dynamicData = isset($sendgrid['dynamic_template_data']) && is_array($sendgrid['dynamic_template_data'])
            ? $sendgrid['dynamic_template_data']
            : null;

        $payload = [];

        // Personalizations
        if (isset($sendgrid['personalizations']) && is_array($sendgrid['personalizations']) && !empty($sendgrid['personalizations'])) {
            $payload['personalizations'] = $sendgrid['personalizations'];
        } else {
            $p = [
                'to' => $this->mapAddresses($m->to),
            ];
            if (!empty($m->cc)) $p['cc'] = $this->mapAddresses($m->cc);
            if (!empty($m->bcc)) $p['bcc'] = $this->mapAddresses($m->bcc);

            // Per-personalization subject can override global subject
            if ($m->subject !== null && $m->subject !== '') {
                $p['subject'] = $m->subject;
            }

            // Per-personalization email headers
            if (!empty($m->headers)) {
                $p['headers'] = $m->headers;
            }

            // Per-personalization custom args
            $customArgs = $m->meta['custom_args'] ?? null;
            if (is_array($customArgs) && !empty($customArgs)) {
                $p['custom_args'] = $customArgs;
            } elseif (isset($sendgrid['custom_args']) && is_array($sendgrid['custom_args'])) {
                $p['custom_args'] = $sendgrid['custom_args'];
            }

            if ($dynamicData !== null) {
                $p['dynamic_template_data'] = $dynamicData;
            }

            $payload['personalizations'] = [$p];
        }

        // Ensure dynamic_template_data is set on each personalization when provided.
        if ($dynamicData !== null) {
            foreach ($payload['personalizations'] as $i => $p) {
                if (!is_array($p)) continue;
                if (!isset($p['dynamic_template_data'])) {
                    $payload['personalizations'][$i]['dynamic_template_data'] = $dynamicData;
                }
            }
        }

        // From
        $payload['from'] = [
            'email' => $m->from?->email,
        ];
        if ($m->from?->name) {
            $payload['from']['name'] = $m->from->name;
        }

        // Reply-to: SendGrid supports reply_to or reply_to_list (mutually exclusive).
        // We map:
        // - if app supplies sendgrid['reply_to_list'] use it
        // - else if message has multiple replyTo, use reply_to_list
        // - else use single reply_to
        if (isset($sendgrid['reply_to_list']) && is_array($sendgrid['reply_to_list']) && !empty($sendgrid['reply_to_list'])) {
            $payload['reply_to_list'] = $sendgrid['reply_to_list'];
        } elseif (!empty($m->replyTo) && count($m->replyTo) > 1) {
            $payload['reply_to_list'] = $this->mapAddresses($m->replyTo);
        } elseif (!empty($m->replyTo)) {
            $payload['reply_to'] = $this->mapAddress($m->replyTo[0]);
        } elseif (isset($sendgrid['reply_to']) && is_array($sendgrid['reply_to'])) {
            $payload['reply_to'] = $sendgrid['reply_to'];
        }

        // Subject: for dynamic templates, template subject can override; SendGrid still accepts global subject.
        if ($m->subject !== null && $m->subject !== '') {
            $payload['subject'] = $m->subject;
        } elseif ($templateId === null) {
            // When no template is used, subject is effectively required; keep empty string to satisfy schema validation.
            $payload['subject'] = '';
        }

        // Template
        if ($templateId !== null && $templateId !== '') {
            $payload['template_id'] = $templateId;
        }

        // Content is optional when using template_id; include only if bodies are present.
        $content = [];
        if ($m->textBody !== null && $m->textBody !== '') {
            $content[] = ['type' => 'text/plain', 'value' => $m->textBody];
        }
        if ($m->htmlBody !== null && $m->htmlBody !== '') {
            $content[] = ['type' => 'text/html', 'value' => $m->htmlBody];
        }
        if (!empty($content)) {
            $payload['content'] = $content;
        }

        // Attachments
        if (!empty($m->attachments)) {
            $payload['attachments'] = array_map(fn(Attachment $a) => [
                'content' => $a->contentBase64,
                'filename' => $a->filename,
                'type' => $a->contentType,
                'disposition' => 'attachment',
            ], $m->attachments);
        }

        // Categories
        $categories = $m->meta['categories'] ?? ($sendgrid['categories'] ?? null);
        if (is_array($categories) && !empty($categories)) {
            $payload['categories'] = array_values(array_unique(array_map('strval', $categories)));
        }

        // Pass-through supported SendGrid top-level knobs (no DB/routes/UI here).
        foreach (['send_at','batch_id','asm','ip_pool_name','mail_settings','tracking_settings'] as $k) {
            if (array_key_exists($k, $sendgrid)) {
                $payload[$k] = $sendgrid[$k];
            }
        }

        // sandbox_mode is nested under mail_settings in SendGrid payload.
        if (array_key_exists('sandbox_mode', $sendgrid)) {
            $payload['mail_settings'] = is_array($payload['mail_settings'] ?? null) ? $payload['mail_settings'] : [];
            $payload['mail_settings']['sandbox_mode'] = ['enable' => (bool)$sendgrid['sandbox_mode']];
        }

        // Allow app to set advanced flags that are already shaped correctly.
        if (isset($sendgrid['extra']) && is_array($sendgrid['extra'])) {
            $payload = array_replace_recursive($payload, $sendgrid['extra']);
        }

        return $payload;
    }

    private function mapAddresses(array $addresses): array
    {
        return array_map(fn($a) => $this->mapAddress($a), $addresses);
    }

    private function mapAddress($a): array
    {
        $out = ['email' => $a->email];
        if ($a->name) $out['name'] = $a->name;
        return $out;
    }
}
