<?php
declare(strict_types=1);

namespace System\Exceptions;

use Exception;

/**
 * YantraException
 *
 * Unified exception for the Yantra framework.
 *
 * - No hard-coded constants / default-messages.
 * - Driven by an external error config:
 *     [
 *       'generic' => [
 *         'code'    => 1000,
 *         'status'  => 500,
 *         'message' => 'An error occurred.',
 *       ],
 *       'validation' => [
 *         'code'    => 1001,
 *         'status'  => 422,
 *         'message' => 'Validation failed.',
 *       ],
 *       ...
 *     ]
 */
class YantraException extends Exception
{
    /** @var array<string,mixed> */
    protected array $context = [];

    /** HTTP-style status code (e.g. 404, 500) */
    protected ?int $statusCode = null;

    /** Error name from config (e.g. "validation", "not_found") */
    protected ?string $errorName = null;

    /**
     * Error configuration
     *
     * Shape:
     *  [
     *    'name' => [
     *      'code'    => int,        // exception code
     *      'status'  => int|null,   // HTTP status (optional)
     *      'message' => string,     // default message
     *    ],
     *    ...
     *  ]
     *
     * @var array<string,array<string,mixed>>
     */
    protected static array $errorConfig = [];

    public function __construct(
        string $message,
        int $code = 0,
        array $context = [],
        ?int $statusCode = null,
        ?string $errorName = null,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context    = $context;
        $this->statusCode = $statusCode;
        $this->errorName  = $errorName;
    }

    /* ============================================================
     * Config management
     * ============================================================ */

    /**
     * Override the entire error config.
     *
     * @param array<string,array<string,mixed>> $config
     */
    public static function setErrorConfig(array $config): void
    {
        static::$errorConfig = $config;
    }

    /**
     * Merge/extend existing config (later keys override earlier ones).
     *
     * @param array<string,array<string,mixed>> $config
     */
    public static function mergeErrorConfig(array $config): void
    {
        static::$errorConfig = array_replace_recursive(static::$errorConfig, $config);
    }

    /**
     * Get the whole config or a single entry by name.
     *
     * @return array<string,mixed>
     */
    public static function getErrorConfig(?string $name = null): array
    {
        if ($name === null) {
            return static::$errorConfig;
        }

        return static::$errorConfig[$name] ?? [];
    }

    /* ============================================================
     * Accessors
     * ============================================================ */

    /**
     * Extra contextual data for logging or error reporting.
     *
     * @return array<string,mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Mutate context (e.g. enrichment by middleware).
     *
     * @param array<string,mixed> $context
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /**
     * HTTP-style status code (if configured).
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Logical error name (key from config).
     */
    public function getErrorName(): ?string
    {
        return $this->errorName;
    }

    /* ============================================================
     * Internal helpers
     * ============================================================ */

    /**
     * Resolve config by name, with a sane fallback.
     *
     * @return array{code:int,status:?int,message:string,name:?string}
     */
    protected static function resolveByName(string $name): array
    {
        if (isset(static::$errorConfig[$name])) {
            $cfg = static::$errorConfig[$name];

            return [
                'code'    => isset($cfg['code']) ? (int)$cfg['code'] : 0,
                'status'  => isset($cfg['status']) ? (int)$cfg['status'] : null,
                'message' => isset($cfg['message']) && $cfg['message'] !== ''
                    ? (string)$cfg['message']
                    : 'An error occurred.',
                'name'    => $name,
            ];
        }

        // Fallback to "generic" if available
        if (isset(static::$errorConfig['generic'])) {
            $generic = static::$errorConfig['generic'];

            return [
                'code'    => isset($generic['code']) ? (int)$generic['code'] : 0,
                'status'  => isset($generic['status']) ? (int)$generic['status'] : null,
                'message' => isset($generic['message']) && $generic['message'] !== ''
                    ? (string)$generic['message']
                    : 'An error occurred.',
                'name'    => 'generic',
            ];
        }

        // Ultimate fallback with no config at all
        return [
            'code'    => 0,
            'status'  => null,
            'message' => 'An error occurred.',
            'name'    => $name,
        ];
    }

    /**
     * Resolve config by numeric code (reverse lookup).
     *
     * @return array{code:int,status:?int,message:string,name:?string}
     */
    protected static function resolveByCode(int $code): array
    {
        foreach (static::$errorConfig as $name => $cfg) {
            if (isset($cfg['code']) && (int)$cfg['code'] === $code) {
                return [
                    'code'    => (int)$cfg['code'],
                    'status'  => isset($cfg['status']) ? (int)$cfg['status'] : null,
                    'message' => isset($cfg['message']) && $cfg['message'] !== ''
                        ? (string)$cfg['message']
                        : 'An error occurred.',
                    'name'    => $name,
                ];
            }
        }

        // Fallback to generic if present
        if (isset(static::$errorConfig['generic'])) {
            $generic = static::$errorConfig['generic'];

            return [
                'code'    => $code,
                'status'  => isset($generic['status']) ? (int)$generic['status'] : null,
                'message' => isset($generic['message']) && $generic['message'] !== ''
                    ? (string)$generic['message']
                    : 'An error occurred.',
                'name'    => 'generic',
            ];
        }

        // No config at all: pure numeric error
        return [
            'code'    => $code,
            'status'  => null,
            'message' => 'An error occurred.',
            'name'    => null,
        ];
    }

    /* ============================================================
     * Factories
     * ============================================================ */

    /**
     * Create exception using an error name from the config.
     *
     * Example:
     *   YantraException::named('validation', 'Email is required', [...]);
     */
    public static function named(
        string $name,
        ?string $message = null,
        array $context = [],
        ?Exception $previous = null
    ): self {
        $cfg = static::resolveByName($name);

        $finalMessage = ($message === null || $message === '')
            ? $cfg['message']
            : $message;

        return new self(
            $finalMessage,
            $cfg['code'],
            $context,
            $cfg['status'],
            $cfg['name'],
            $previous
        );
    }

    /**
     * Create exception using a numeric code, looking it up in config.
     * If not found, falls back to "generic" or a plain exception.
     */
    public static function fromCode(
        int $code,
        ?string $message = null,
        array $context = [],
        ?Exception $previous = null
    ): self {
        $cfg = static::resolveByCode($code);

        $finalMessage = ($message === null || $message === '')
            ? $cfg['message']
            : $message;

        return new self(
            $finalMessage,
            $cfg['code'],
            $context,
            $cfg['status'],
            $cfg['name'],
            $previous
        );
    }

    /**
     * Backwards-compatible alias for fromCode().
     * (If you prefer, switch your code to use named() instead.)
     */
    public static function make(
        int $code,
        ?string $message = null,
        array $context = [],
        ?Exception $previous = null
    ): self {
        return static::fromCode($code, $message, $context, $previous);
    }

    /* ============================================================
     * Semantic helpers (name-based)
     * ============================================================ */

    /**
     * Generic error.
     */
    public static function generic(string $message = '', array $context = []): self
    {
        return static::named('generic', $message, $context);
    }

    /**
     * Database / SQL error.
     */
    public static function sql(
        string $message = '',
        array $context = [],
        ?Exception $previous = null
    ): self {
        return static::named('sql', $message, $context, $previous);
    }

    /**
     * Validation failed.
     */
    public static function validation(string $message = '', array $context = []): self
    {
        return static::named('validation', $message, $context);
    }

    /**
     * Domain-level not found (user/entity/etc).
     */
    public static function notFound(string $message = '', array $context = []): self
    {
        return static::named('not_found', $message, $context);
    }

    /**
     * Authentication failure (credentials).
     */
    public static function auth(string $message = '', array $context = []): self
    {
        return static::named('auth', $message, $context);
    }

    /**
     * Authorization failure (no permission).
     */
    public static function forbidden(string $message = '', array $context = []): self
    {
        return static::named('forbidden', $message, $context);
    }

    /**
     * Conflict / duplicate / already exists.
     */
    public static function conflict(string $message = '', array $context = []): self
    {
        return static::named('conflict', $message, $context);
    }

    /**
     * Rate limit exceeded.
     */
    public static function rateLimit(string $message = '', array $context = []): self
    {
        return static::named('rate_limit', $message, $context);
    }

    /**
     * Internal / unexpected server error.
     */
    public static function internal(
        string $message = '',
        array $context = [],
        ?Exception $previous = null
    ): self {
        return static::named('internal', $message, $context, $previous);
    }

    /* --------- HTTP-style helpers (also name-based) --------- */

    public static function badRequest(string $message = '', array $context = []): self
    {
        return static::named('bad_request', $message, $context);
    }

    public static function unauthorized(string $message = '', array $context = []): self
    {
        return static::named('unauthorized', $message, $context);
    }

    public static function tooManyRequests(string $message = '', array $context = []): self
    {
        return static::named('too_many_requests', $message, $context);
    }

    public static function serviceUnavailable(string $message = '', array $context = []): self
    {
        return static::named('service_unavailable', $message, $context);
    }

    public static function gatewayTimeout(string $message = '', array $context = []): self
    {
        return static::named('gateway_timeout', $message, $context);
    }
}