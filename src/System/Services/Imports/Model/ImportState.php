<?php
declare(strict_types=1);

namespace System\Services\Imports\Model;

final class ImportState
{
    public function __construct(
        public string $id,
        public string $sourcePath,
        public string $status = 'created',
        public int $totalRows = 0,
        public int $processedRows = 0,
        public int $failedRows = 0,
        public array $meta = [],
        public int $createdAtUnix = 0,
        public int $updatedAtUnix = 0
    ) {
        $now = time();
        if ($this->createdAtUnix <= 0) $this->createdAtUnix = $now;
        if ($this->updatedAtUnix <= 0) $this->updatedAtUnix = $now;
    }
}
