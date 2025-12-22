<?php

namespace System\Helpers;

use System\Exceptions\ValidationException;
use System\Exceptions\CsrfException;
use System\Http\Request;

/**
 * Trait FormIntegrationTrait
 *
 * Controller helper trait:
 *  - validateCsrfOrThrow()
 *  - flashOldFromRequest()
 *  - failValidationAndReturn()
 *
 * Usage in controllers: use FormIntegrationTrait;
 */
trait FormIntegrationTrait
{
    /**
     * Validate CSRF or throw CsrfException.
     *
     * @throws CsrfException
     */
    protected function validateCsrfOrThrow(): void
    {
        if (!FormHelper::validateCsrfToken(null)) {
            throw new CsrfException();
        }
    }

    /**
     * Flash the current request input into session as "old" so views can re-populate.
     *
     * @param Request|null $request Optional request; otherwise uses $_POST
     * @return void
     */
    protected function flashOldFromRequest(?Request $request = null): void
    {
        // If you use a Request abstraction, extract input keys: $request->all()
        if ($request !== null && method_exists($request, 'all')) {
            $data = $request->getAll();
        } else {
            $data = $_POST ?? [];
        }

        FormHelper::flashOld($data);
    }

    /**
     * Called when validation fails. This method:
     *  - flashes old input
     *  - throws ValidationException or returns an array suitable for JSON responses
     *
     * Two usage patterns:
     *  1) throw new ValidationException($errors) => caught by global handler
     *  2) return $this->failValidationAndReturn($errors) => call returns response payload
     *
     * @param array $errors Associative field => messages
     * @param bool $throw Whether to throw ValidationException (default true).
     * @return array|null When $throw === false, returns response payload array.
     * @throws ValidationException
     */
    protected function failValidationAndReturn(array $errors, bool $throw = true): ?array
    {
        // Flash old input so subsequent view rendering can repopulate fields
        $this->flashOldFromRequest();

        if ($throw) {
            throw new ValidationException($errors);
        }

        // Return a standard JSON-friendly payload
        return [
            'success' => false,
            'errors' => $errors,
        ];
    }

    /**
     * Helper to produce a redirect back response when validation fails.
     *
     * This method does not assume any Response class — it demonstrates
     * two simple patterns: JSON response or "redirect back" via Location header.
     *
     * @param array $errors
     * @param bool $json If true, return JSON array; otherwise emit a redirect header and exit.
     * @param string|null $backUrl If provided, use this URL as redirect target; otherwise use HTTP_REFERER or "/".
     * @return array|null
     */
    protected function redirectBackWithErrors(array $errors, bool $json = false, ?string $backUrl = null): ?array
    {
        // Flash old inputs
        $this->flashOldFromRequest();

        if ($json) {
            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        $target = $backUrl;
        if ($target === null) {
            $target = $_SERVER['HTTP_REFERER'] ?? '/';
        }

        // If you have a Response helper, use that instead. This is a minimal fallback:
        header('Location: ' . $target);
        http_response_code(302);
        // Typically framework frameworks immediately `exit` after setting redirect.
        exit;
    }
}
