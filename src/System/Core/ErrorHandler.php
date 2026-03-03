<?php
declare(strict_types=1);

namespace System\Core;

use System\Contracts\LoggerInterface;
use System\Http\Request;
use System\Http\Response;
use Throwable;

/**
 * Centralized Error Handler
 * 
 * Handles uncaught exceptions and provides uniform error responses.
 */
class ErrorHandler
{
    private ?LoggerInterface $logger;
    private string $environment;

    public function __construct(?LoggerInterface $logger = null, string $environment = 'production')
    {
        $this->logger = $logger;
        $this->environment = $environment;
    }

    /**
     * Handle an exception and return a Response.
     */
    public function handle(Throwable $e, Request $req, Response $res): Response
    {
        // Log the exception (if logger available)
        $this->logger?->error($e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        // Determine response format (JSON for API, HTML for web)
        if ($req->acceptsJson() || $req->isAjax()) {
            return $this->jsonResponse($e, $res);
        }

        return $this->htmlResponse($e, $res);
    }

    /**
     * Generate JSON error response.
     */
    private function jsonResponse(Throwable $e, Response $res): Response
    {
        $status = $this->getStatusCode($e);
        
        $data = [
            'error' => true,
            'message' => $this->environment === 'development' 
                ? $e->getMessage() 
                : 'An error occurred.',
        ];

        if ($this->environment === 'development') {
            $data['debug'] = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ];
        }

        return $res->json($data, $status);
    }

    /**
     * Generate HTML error response.
     */
    private function htmlResponse(Throwable $e, Response $res): Response
    {
        $status = $this->getStatusCode($e);
        
        if ($this->environment === 'development') {
            $html = $this->renderDebugPage($e, $status);
        } else {
            $html = $this->renderProductionPage($status);
        }

        return $res->html($html, $status);
    }

    /**
     * Render detailed debug page for development.
     */
    private function renderDebugPage(Throwable $e, int $status): string
    {
        $class = get_class($e);
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $e->getLine();
        $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error {$status}</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #1e1e1e; color: #d4d4d4; }
        h1 { color: #f48771; }
        .exception { background: #252526; padding: 15px; border-left: 4px solid #f48771; margin: 10px 0; }
        .trace { background: #0e0e0e; padding: 10px; overflow-x: auto; white-space: pre-wrap; }
        .meta { color: #858585; }
    </style>
</head>
<body>
    <h1>⚠ {$class}</h1>
    <div class="exception">
        <strong>Message:</strong> {$message}
    </div>
    <div class="meta">
        <strong>File:</strong> {$file}<br>
        <strong>Line:</strong> {$line}
    </div>
    <h2>Stack Trace</h2>
    <div class="trace">{$trace}</div>
</body>
</html>
HTML;
    }

    /**
     * Render generic error page for production.
     */
    private function renderProductionPage(int $status): string
    {
        $title = match ($status) {
            404 => '404 Not Found',
            403 => '403 Forbidden',
            500 => '500 Internal Server Error',
            default => "{$status} Error",
        };

        $message = match ($status) {
            404 => 'The page you are looking for could not be found.',
            403 => 'You do not have permission to access this resource.',
            500 => 'An unexpected error occurred. Please try again later.',
            default => 'An error occurred while processing your request.',
        };

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
               display: flex; align-items: center; justify-content: center; 
               height: 100vh; margin: 0; background: #f5f5f5; }
        .container { text-align: center; padding: 40px; background: white; 
                     border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { font-size: 72px; margin: 0; color: #333; }
        p { color: #666; font-size: 18px; }
        a { color: #007bff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>{$status}</h1>
        <p>{$message}</p>
        <a href="/">← Go to Homepage</a>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Extract HTTP status code from exception.
     */
    private function getStatusCode(Throwable $e): int
    {
        if (method_exists($e, 'getStatusCode')) {
            return (int)$e->getStatusCode();
        }

        // Default to 500 for uncaught exceptions
        return 500;
    }
}
