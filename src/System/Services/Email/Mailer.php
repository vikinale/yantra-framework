<?php
declare(strict_types=1);

namespace System\Services\Email;

use System\Services\\Email\Contracts\QueueInterface;
use System\Services\\Email\Contracts\SendObserverInterface;
use System\Services\\Email\Contracts\TemplateRendererInterface;
use System\Services\\Email\Contracts\TransportInterface;
use System\Services\\Email\Queue\QueueJob;
use System\Services\\Email\Queue\SendEmailPayload;

final class Mailer
{
    private ?TemplateRendererInterface $renderer = null;
    private ?QueueInterface $queue = null;
    private ?SendObserverInterface $observer = null;

    public function __construct(private TransportInterface $transport) {}

    public function withRenderer(TemplateRendererInterface $renderer): self
    {
        $this->renderer = $renderer;
        return $this;
    }

    public function withQueue(QueueInterface $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    public function withObserver(SendObserverInterface $observer): self
    {
        $this->observer = $observer;
        return $this;
    }

    public function message(): EmailMessage
    {
        return new EmailMessage();
    }

    public function send(EmailMessage $message): TransportResult
    {
        $this->observer?->beforeSend($message);
        try {
            $res = $this->transport->send($message);
            $this->observer?->afterSend($message, $res);
            return $res;
        } catch (\Throwable $e) {
            $this->observer?->onError($message, $e);
            throw $e;
        }
    }

    /**
     * Render a template, then send or queue based on caller.
     */
    public function render(string $template, array $data = []): TemplateResult
    {
        if (!$this->renderer) {
            throw new \RuntimeException('No TemplateRenderer configured');
        }
        return $this->renderer->render($template, $data);
    }

    /**
     * Queue a send job (requires Queue configured). App runs worker.
     */
    public function queueSend(EmailMessage $message, int $delaySeconds = 0, int $maxAttempts = 5): string
    {
        if (!$this->queue) {
            throw new \RuntimeException('No Queue configured');
        }
        $payload = SendEmailPayload::fromMessage($message);
        $job = QueueJob::make(
            type: 'email.send',
            payload: $payload->toArray(),
            delaySeconds: $delaySeconds,
            maxAttempts: $maxAttempts
        );
        return $this->queue->push($job);
    }

    /**
     * Helper: create message from TemplateResult.
     */
    public function applyTemplate(EmailMessage $message, TemplateResult $tpl): EmailMessage
    {
        $message->subject = $tpl->subject;
        if ($tpl->html !== null) $message->htmlBody = $tpl->html;
        if ($tpl->text !== null) $message->textBody = $tpl->text;
        if (!empty($tpl->meta)) {
            // Template meta should *augment* message meta; message wins on conflicts.
            $message->meta = array_replace_recursive($tpl->meta, $message->meta);
        }
        return $message;
    }
}
