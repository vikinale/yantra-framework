<?php
declare(strict_types=1);

namespace System;

use System\Http\Request;
use System\Http\Response;
use System\Security\Csrf;
use Throwable;

/**
 * Controller (framework-level)
 *
 * Responsibilities:
 *  - Hold Request/Response
 *  - Provide minimal, safe primitives for derived controllers
 *  - Provide hooks for initialization + exception reporting
 *
 * Non-responsibilities:
 *  - Rendering, JSON envelopes, uploads, domain workflows (belongs in BaseController/services)
 */
abstract class Controller
{
    protected Request $request;
    protected Response $response;

    public function __construct(Request $request, Response $response)
    {
        $this->request  = $request;
        $this->response = $response;

        $this->init();
    }

    /**
     * Override in derived controllers if needed.
     */
    protected function init(): void
    {
        // no-op
    }

    /* =====================================================
     | Accessors
     ===================================================== */

    protected function req(): Request
    {
        return $this->request;
    }

    protected function res(): Response
    {
        return $this->response;
    }

    protected function wantsJson(): bool
    {
        return $this->request->wantsJson();
    }

    /* =====================================================
     | HTTP helpers
     ===================================================== */

    protected function methodIs(string $method): bool
    {
        $m = strtoupper(trim($method));
        return strtoupper($this->request->getMethod()) === $m;
    }

    protected function isGet(): bool { return $this->methodIs('GET'); }
    protected function isPost(): bool { return $this->methodIs('POST'); }
    protected function isPut(): bool { return $this->methodIs('PUT'); }
    protected function isPatch(): bool { return $this->methodIs('PATCH'); }
    protected function isDelete(): bool { return $this->methodIs('DELETE'); }

    /* =====================================================
     | Security primitives (optional to use)
     ===================================================== */

    /**
     * CSRF enforcement wrapper.
     *
     * @param string $scope Token scope/key, e.g. "user_login"
     * @param bool $rotate Whether to rotate token on validate
     */
    protected function validateCsrf(string $scope, bool $rotate = true): bool
    {
        $token = (string)($this->request->csrfToken() ?? '');
        return Csrf::validate($token, $scope, $rotate);
    }

    /* =====================================================
     | Error/reporting hooks (minimal)
     ===================================================== */

    /**
     * Central reporting hook (override to integrate logger).
     */
    protected function report(Throwable $e): void
    {
        error_log($e->getMessage());
    }

    /**
     * Optional: for debug/dev mode later.
     */
    protected function safeErrorMessage(Throwable $e, string $fallback = 'An unexpected error occurred.'): string
    {
        // Keep safe by default (do not leak internal details).
        return $fallback;
    }
}