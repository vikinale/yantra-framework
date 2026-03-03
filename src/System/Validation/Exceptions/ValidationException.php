<?php
declare(strict_types=1);

namespace System\Validation\Exceptions;

use System\Validation\ErrorBag;

final class ValidationException extends \RuntimeException
{
    public function __construct(
        public readonly ErrorBag $errors,
        string $message = 'Validation failed',
        int $code = 422,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
