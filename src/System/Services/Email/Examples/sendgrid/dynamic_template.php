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
$msg->from = new Address('orders@example.com', 'Orders');
$msg->to[] = new Address('user@example.com', 'User');

// Dynamic Template IDs start with "d-".
$msg->meta['sendgrid'] = [
    'template_id' => getenv('SENDGRID_DYNAMIC_TEMPLATE_ID'),
    'dynamic_template_data' => [
        'customer_name' => 'Alex',
        'confirmation_number' => '123abc456def789hij0',
    ],
];

$res = $mailer->send($msg);

echo "Queued: status={$res->statusCode}, X-Message-ID={$res->messageId}\n";
