<?php
declare(strict_types=1);

namespace System\Services\Email\Contracts;

use System\Services\\Email\Bounce\BounceEvent;

interface BounceHandlerInterface
{
    /**
     * App hook: persist bounce, suppress address, notify, etc.
     */
    public function handle(BounceEvent $event): void;
}
