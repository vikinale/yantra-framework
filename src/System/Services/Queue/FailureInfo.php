<?php
declare(strict_types=1);

namespace System\Services\Queue;

final class FailureInfo
{
    public function __construct(
        public readonly string $errorClass,
        public readonly string $message,
        public readonly string $stack,
        public readonly int $failedAtUnix
    ) {}

    public static function fromThrowable(\Throwable $e): self
    {
        return new self(
            errorClass: get_class($e),
            message: (string)$e->getMessage(),
            stack: (string)$e->getTraceAsString(),
            failedAtUnix: time()
        );
    }
}
