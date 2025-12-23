<?php
namespace System\Utilities;

use System\Exceptions\FormException;

/**
 * ValidationException - contains validation errors in $errors array.
 */
class ValidationException extends FormException
{
    protected array $errors;

    public function __construct(array $errors = [], string $message = "Validation failed", int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function first(string $key)
    {
        return $this->errors[$key][0] ?? null;
    }
}
