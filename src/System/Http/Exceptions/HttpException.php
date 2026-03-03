<?php
declare(strict_types=1);

namespace System\Http\Exceptions;

/**
 * HttpException — Represents an HTTP error that should abort request processing.
 *
 * Used by the abort() helper to signal HTTP errors with appropriate status codes.
 *
 * Usage:
 *   throw new HttpException(404, 'Page not found');
 *   throw new HttpException(403, 'Forbidden');
 */
class HttpException extends \RuntimeException
{
    private int $statusCode;
    private array $headers;

    /**
     * @param int    $statusCode HTTP status code (4xx or 5xx).
     * @param string $message    Human-readable error message.
     * @param \Throwable|null $previous Previous exception for chaining.
     * @param array  $headers    Additional HTTP headers to send.
     */
    public function __construct(
        int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
        array $headers = []
    ) {
        $this->statusCode = $statusCode;
        $this->headers    = $headers;

        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Get the HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the additional HTTP headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
