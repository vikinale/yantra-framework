<?php

namespace System\Exceptions;

use Exception;

class FieldException extends Exception
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
        string $field="",
        int $code = 0,
        Exception $previous = null,
        array $data = []
    ) {
        parent::__construct($message, $code, $previous);
        $data['field'] = $field;
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
