<?php
declare(strict_types=1);

namespace System\Services\Email\Exceptions;

final class TransportException extends \RuntimeException
{
    public static function wrap(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, 0, $previous);
    }
}
