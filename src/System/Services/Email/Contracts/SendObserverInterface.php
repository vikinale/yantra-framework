<?php
declare(strict_types=1);

namespace System\Services\Email\Contracts;

use System\Services\Email\EmailMessage;
use System\Services\Email\TransportResult;

interface SendObserverInterface
{
    public function beforeSend(EmailMessage $message): void;
    public function afterSend(EmailMessage $message, TransportResult $result): void;
    public function onError(EmailMessage $message, \Throwable $error): void;
}
