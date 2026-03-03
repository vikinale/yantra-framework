<?php
declare(strict_types=1);

namespace System\Services\Imports\Model;

final class ImportError
{
    public function __construct(
        public int $rowNumber,
        public string $field,
        public string $message,
        public array $meta = []
    ) {}
}
