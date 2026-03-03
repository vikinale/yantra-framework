<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use System\Services\\Email\Bounce\BounceProcessor;
use System\Services\\Email\Bounce\SendGridEventWebhookParser;
use System\Services\\Email\Bounce\SendGridEventWebhookVerifier;
use System\Services\\Email\Bounce\BounceEvent;
use System\Services\\Email\Contracts\BounceHandlerInterface;

$rawBody = file_get_contents('php://input');

// Optional signature verification (enable "Signed Event Webhook" in SendGrid UI)
$sig = $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE'] ?? '';
$ts = $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP'] ?? '';

if (getenv('SENDGRID_EVENT_WEBHOOK_PUBLIC_KEY') && $sig && $ts) {
    $verifier = new SendGridEventWebhookVerifier();
    if (!$verifier->verify(getenv('SENDGRID_EVENT_WEBHOOK_PUBLIC_KEY'), $ts, $sig, $rawBody)) {
        http_response_code(401);
        echo "Invalid signature";
        exit;
    }
}

$processor = new BounceProcessor(
    parsers: [new SendGridEventWebhookParser()],
    handler: new class implements BounceHandlerInterface {
        public function handle(BounceEvent $event): void
        {
            // App-owned persistence: store suppression info, metrics, logs, etc.
            error_log(json_encode([
                'email' => $event->email,
                'type' => $event->type,
                'messageId' => $event->messageId,
                'reason' => $event->reason,
                'timestamp' => $event->timestamp,
            ]));
        }
    }
);

$events = $processor->processBatch($rawBody);

http_response_code(200);
echo "OK (" . count($events) . ")";
