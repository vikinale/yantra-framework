<?php
declare(strict_types=1);

namespace System\Core;

use InvalidArgumentException;
use System\Controller;
use System\Http\ApiResponse;
use System\Http\Request;
use System\Http\Response;
use System\Theme\ThemeManager;
use Throwable;

/**
 * BaseController
 *
 * Shared controller utilities for Yantra.
 */
abstract class BaseController extends Controller
{
    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
    }

    public function notfound(array $params = []): Response {
        $this->response = $this->response->withStatus(404);
        if(ThemeManager::instance()->hasView("404")){ 
                return $this->render("404", ['params' => $params], 'index');
        }
        return $this->response->html("<h1>404 Not Found</h1>\n", 404);
    }

    protected function show_error(mixed ...$r): void
    {
        foreach ($r as $x) {
            if (is_array($x) || is_object($x)) {
                error_log((string)json_encode($x, JSON_UNESCAPED_UNICODE));
                continue;
            }
            if (is_bool($x)) {
                error_log($x ? 'Bool:true' : 'Bool:false');
                continue;
            }
            if (is_string($x)) {
                error_log($x);
                continue;
            }
            error_log(gettype($x) . ':' . (string)$x);
        }
    }
    /* =====================================================
     | Response helpers (JSON)
     ===================================================== */
    protected function success(mixed $data = null, int $status = 200, string $message = ''): Response
    {
        return ApiResponse::createSuccess($this->response, $data, $message, $status);
    }

    protected function error(string $message, int $status = 400, array $errors = [], string $code = 'error'): Response
    {
        return ApiResponse::createError($this->response, $message, $status, $errors, $code);
    }

    protected function validationError(array $errors, string $message = 'Validation failed'): Response
    {
        return ApiResponse::createValidation($this->response, $errors, $message, 422);
    }

    // Deprecated helpers: now alias to standard ones
    protected function successWith(Response $response, mixed $data = null, int $status = 200, string $message = '', ?string $redirect = null): Response
    {
        return ApiResponse::createSuccess($response, $data, $message, $status, $redirect);
    }

    protected function errorWith(Response $response, string $message, int $status = 400, array $errors = [], string $code = 'error'): Response
    {
        return ApiResponse::createError($response, $message, $status, $errors, $code);
    }

    protected function validationWith(Response $response, array $errors, string $message = 'Validation failed'): Response
    {
        return ApiResponse::createValidation($response, $errors, $message, 422);
    }

    /* =====================================================
     | Redirect helpers (HTML)
     ===================================================== */

    protected function redirect(string $url, int $status = 302): Response
    {
        return $this->response->redirect($url, $status);
    }

    /**
     * Correct redirect after POST/PUT/PATCH.
     */
    protected function redirectAfterPost(string $url): Response
    {
        return $this->response->redirectSeeOther($url);
    }

    /* =====================================================
     | Hybrid responder (JSON or HTML)
     ===================================================== */

    protected function respond(
        string $redirect,
        mixed $data = null,
        string $message = '',
        int $status = 200
    ): Response {
        if ($this->request->isAjax() || $this->request->acceptsJson()) {
            return ApiResponse::createSuccess($this->response, $data, $message, $status, $redirect);
        }

        return $this->redirectAfterPost($redirect);
    }

    /* =====================================================
     | View rendering
     ===================================================== */

    protected function render(string $view, array $data = [], ?string $layout = null): Response
    {
        $this->response = $this->response->view($view, $data, $layout);
        return $this->response;
    }


    /* =====================================================
     | Exception handling
     ===================================================== */

    protected function handleException(Throwable $e, int $status = 500): Response
    {
        error_log($e->getMessage());

        if ($this->request->isAjax() || $this->request->acceptsJson()) {
            return ApiResponse::createError(
                $this->response,
                'An unexpected error occurred.',
                $status
            );
        }

        return $this->response->text('Internal Server Error', $status);
    }

    /* =====================================================
     | Input helpers
     ===================================================== */

    protected function old(array $payload, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $payload)) {
                $out[$k] = is_string($payload[$k])
                    ? trim($payload[$k])
                    : $payload[$k];
            }
        }
        return $out;
    }

    protected function int(string $key, int $default = 0): int
    {
        $v = $this->request->input($key);
        return is_numeric($v) ? (int)$v : $default;
    }

 
    protected function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new InvalidArgumentException('Failed to create directory: ' . $dir);
            }
        }
    }
}
