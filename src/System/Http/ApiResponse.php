<?php
declare(strict_types=1);

namespace System\Http;

/**
 * Unified JSON API response helper.
 *
 * Success:
 *  { success:true, message, data?, redirect?, status, status_text }
 *
 * Error:
 *  { success:false, message, code, errors?, meta?, status, status_text }
 */
final class ApiResponse
{
    private static function phrase(int $status): string
    {
        return HttpStatus::phrase($status);
    }

    /* =============================================================
     | SUCCESS
     ============================================================= */
    public static function createSuccess(
        Response $response,
        mixed $data = null,
        string $message = '',
        int $status = 200,
        ?string $redirect = null
    ): Response {
        $phrase = self::phrase($status);
        if ($message === '') {
            $message = $phrase;
        }

        $payload = [
            'success'     => true,
            'message'     => $message,
            'messages'    => $message !== '' ? [$message] : [],
            'status'      => $status,
            'status_text' => $phrase,
            'data'        => $data,
        ];

        if ($redirect) {
            $payload['redirect'] = $redirect;
        }

        return $response->json($payload, $status);
    }

    public static function success(
        Response $response,
        mixed $data = null,
        string $message = '',
        int $status = 200,
        ?string $redirect = null
    ): never {
        self::createSuccess($response, $data, $message, $status, $redirect)->emitAndExit();
    }

    /* =============================================================
     | GENERIC ERROR
     ============================================================= */
    public static function createError(
        Response $response,
        string $message = '',
        int $status = 400,
        array $errors = [],
        string $code = 'error',
        array $meta = []
    ): Response {
        $phrase = self::phrase($status);
        if ($message === '') {
            $message = $phrase;
        }

        $payload = [
            'success'     => false,
            'message'     => $message,
            'formError'   => $message,
            'messages'    => [], 
            'code'        => $code,
            'status'      => $status,
            'status_text' => $phrase,
        ];

        $errors = self::sanitizeErrors($errors);
        if ($errors) {
            $payload['errors']      = $errors;
            $payload['fieldErrors'] = $errors;
        } else {
            // Ensure fieldErrors is always an object/array if preferred, but usually omit if empty?
            // SPA might expect it to exist. Let's keep it consistent with errors (omitted if empty).
        }

        if ($meta) {
            $payload['meta'] = $meta;
        }

        return $response->json($payload, $status);
    }

    public static function error(
        Response $response,
        string $message = '',
        int $status = 400,
        array $errors = [],
        string $code = 'error',
        array $meta = []
    ): never {
        self::createError($response, $message, $status, $errors, $code, $meta)->emitAndExit();
    }

    /* =============================================================
     | VALIDATION
     ============================================================= */
    public static function createValidation(
        Response $response,
        array $fieldErrors,
        string $message = '',
        int $status = 422,
        array $meta = []
    ): Response {
        return self::createError(
            $response,
            $message,
            $status,
            $fieldErrors,
            'validation_error',
            $meta
        );
    }

    public static function validation(
        Response $response,
        array $fieldErrors,
        string $message = '',
        int $status = 422,
        array $meta = []
    ): never {
        self::createValidation($response, $fieldErrors, $message, $status, $meta)->emitAndExit();
    }

    /* =============================================================
     | 429 — RATE LIMIT (Retry-After wired)
     ============================================================= */
    public static function tooManyRequests(
        Response $response,
        string $message = '',
        int|string|null $retryAfter = null,
        array $meta = []
    ): never {
        $status = 429;
        $phrase = self::phrase($status);
        if ($message === '') $message = $phrase;

        if ($retryAfter !== null) {
            // RFC 9110: Retry-After = seconds | HTTP-date
            $response = $response->withHeader('Retry-After', (string)$retryAfter);
            $meta['retry_after'] = $retryAfter;
        }

        self::error(
            $response,
            $message,
            $status,
            [],
            'rate_limited',
            $meta
        );
    }

    /* =============================================================
     | SHORTCUTS
     ============================================================= */
    public static function unauthorized(Response $r, string $m = '', array $e = [], array $meta = []): never
    {
        self::error($r, $m, 401, $e, 'unauthorized', $meta);
    }

    public static function forbidden(Response $r, string $m = '', array $e = [], array $meta = []): never
    {
        self::error($r, $m, 403, $e, 'forbidden', $meta);
    }

    public static function notFound(Response $r, string $m = '', array $meta = []): never
    {
        self::error($r, $m, 404, [], 'not_found', $meta);
    }

    public static function conflict(Response $r, string $m = '', array $meta = []): never
    {
        self::error($r, $m, 409, [], 'conflict', $meta);
    }

    /* =============================================================
     | ERROR SANITIZATION (HARD GUARANTEE)
     ============================================================= */
    private static function sanitizeErrors(array $errors): array
    {
        $out = [];

        foreach ($errors as $field => $msgs) {
            $field = (string)$field;

            // Never expose secrets
            if (in_array($field, ['password','pass','pwd','token','password_confirmation'], true)) {
                continue;
            }

            if (!is_array($msgs)) {
                $msgs = [$msgs];
            }

            $clean = [];
            foreach ($msgs as $m) {
                if (is_string($m)) {
                    $m = trim($m);
                    if ($m !== '') $clean[] = $m;
                } elseif (is_scalar($m)) {
                    $clean[] = (string)$m;
                }
            }

            $clean = array_values(array_unique($clean));
            if ($clean) {
                $out[$field] = $clean;
            }
        }

        return $out;
    }

    /**
     * Success response with no-store (use for login/logout/session endpoints).
     */
    public static function authSuccess(
        Response $response,
        mixed $data = null,
        string $message = '',
        int $status = 200,
        ?string $redirect = null
    ): never {
        $response = $response->noStore();
        self::success($response, $data, $message, $status, $redirect);
    }

    /**
     * Error response with no-store (use for login failures, token issues, etc.).
     */
    public static function authError(
        Response $response,
        string $message = '',
        int $status = 400,
        array $errors = [],
        string $code = 'error',
        array $meta = []
    ): never {
        $response = $response->noStore();
        self::error($response, $message, $status, $errors, $code, $meta);
    }

    /**
     * JSON + Location header redirect (API-friendly).
     * Defaults to 303 (See Other). Useful when SPA wants Location header too.
     */
    public static function redirect(
        Response $response,
        string $location,
        string $message = '',
        int $status = 303,
        array $meta = []
    ): never {
        $location = str_replace(["\r", "\n"], '', $location);

        // You already validate redirect schemes in Response::redirect(),
        // but this path uses headers directly, so keep minimal safety:
        if (preg_match('/^(javascript:|data:)/i', $location)) {
            self::error($response, 'Invalid redirect URL scheme.', 400, [], 'bad_redirect');
        }

        $response = $response->withHeader('Location', $location);

        self::error(
            $response,
            $message !== '' ? $message : HttpStatus::phrase($status),
            $status,
            [],
            'redirect',
            array_merge(['location' => $location], $meta)
        );
    }

}
