<?php
declare(strict_types=1);

namespace System\Services\Imports\Model;

final class RollbackRecord
{
    public function __construct(
        public string $type,
        public array $payload,
        public int $createdAtUnix = 0
    ) {
        if ($this->createdAtUnix <= 0) $this->createdAtUnix = time();
    }
}
