<?php
declare(strict_types=1);

namespace System\Services\Queue\Contracts;

use System\Services\Queue\JobContext;

interface JobHandlerInterface
{
    /** @param array<string,mixed> $data */
    public function handle(array $data, JobContext $ctx): void;
}
