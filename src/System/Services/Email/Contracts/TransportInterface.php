<?php
declare(strict_types=1);

namespace System\Services\Email\Contracts;

use System\Services\Email\EmailMessage;
use System\Services\Email\TransportResult;

interface TransportInterface
{
    /**
     * Sends an email immediately.
     * Implementations must throw TransportException on failures.
     */
    public function send(EmailMessage $message): TransportResult;
}
