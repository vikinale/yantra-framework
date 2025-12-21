<?php

namespace System\Exceptions;

use Exception;

class FormException extends Exception
{
    /**
     * @var array Additional data related to the exception
     */
    protected $data = [];

    /**
     * FormException constructor.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param \Exception|null $previous Previous exception used for chaining
     * @param array $data Additional data related to the exception
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        Exception $previous = null,
        array $data = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->data = $data;
    }

    /**
     * Get additional data related to the exception.
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Set additional data related to the exception.
     *
     * @param array $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /**
     * Get a detailed string representation of the exception.
     *
     * @return string
     */
    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n" . $this->getTraceAsString();
    }
}

/**
 * CSRF failure.
 */
class CsrfException extends FormException
{
    protected int $httpStatus = 400;

    public function __construct(string $message = 'Invalid CSRF token', int $code = 0)
    {
        parent::__construct($message, $code);
    }

    public function getStatus(): int
    {
        return $this->httpStatus;
    }
}

/**
 * Validation failure. Holds an array of errors.
 */
class ValidationException extends FormException
{
    /**
     * @var array<string, string[]|string> Field => [messages]
     */
    protected array $errors;

    protected int $httpStatus = 422;

    public function __construct(array $errors = [], string $message = 'Validation failed', int $code = 0)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    /**
     * @return array<string, string[]|string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getStatus(): int
    {
        return $this->httpStatus;
    }
}
