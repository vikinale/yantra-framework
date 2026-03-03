<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use System\Services\\Email\Address;
use System\Services\\Email\EmailMessage;
use System\Services\\Email\Mailer;
use System\Services\\Email\Transport\SendGridTransport;

$transport = new SendGridTransport(apiKey: getenv('SENDGRID_API_KEY'));
$mailer = new Mailer($transport);

$msg = new EmailMessage();
$msg->from = new Address('no-reply@example.com', 'My App');
$msg->to[] = new Address('user@example.com', 'User');
$msg->subject = 'Hello from SendGrid';
$msg->textBody = 'This is a test message.';

$res = $mailer->send($msg);

echo "Sent: status={$res->statusCode}, X-Message-ID={$res->messageId}\n";
