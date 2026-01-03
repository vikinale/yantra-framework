<?php
declare(strict_types=1);

namespace Core;

use System\Controller;
use System\Http\ApiResponse;
use System\Http\Request;
use System\Http\Response;
use System\Theme\ThemeManager;
use Throwable;
use Exception;
use InvalidArgumentException;
use System\Helpers\PathHelper;
use System\Helpers\UploadHelper;

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

    /* =====================================================
     | Content negotiation
     ===================================================== */

    protected function wantsJson(): bool
    {
        return $this->request->wantsJson();
    }

    /* =====================================================
     | Response helpers (JSON)
     ===================================================== */

    protected function success(mixed $data = null, int $status = 200, string $message = ''): never
    {
        ApiResponse::success($this->response, $data, $message, $status);
    }

    protected function error(string $message, int $status = 400, array $errors = [], string $code = 'error'): never
    {
        ApiResponse::error($this->response, $message, $status, $errors, $code);
    }

    protected function validationError(array $errors, string $message = 'Validation failed'): never
    {
        ApiResponse::validation($this->response, $errors, $message, 422);
    }

    protected function successWith(Response $response, mixed $data = null, int $status = 200, string $message = '', ?string $redirect = null): void
    {
        ApiResponse::success($response, $data, $message, $status, $redirect);
    }

    protected function errorWith(Response $response, string $message, int $status = 400, array $errors = [], string $code = 'error'): never
    {
        ApiResponse::error($response, $message, $status, $errors, $code);
    }

/* =====================================================
     | Redirect helpers (HTML)
     ===================================================== */

    protected function redirect(string $url, int $status = 302): void
    {
        $this->response->redirect($url, $status)->emitAndExit();
    }

    /**
     * Correct redirect after POST/PUT/PATCH.
     */
    protected function redirectAfterPost(string $url): void
    {
        $this->response->redirectSeeOther($url)->emitAndExit();
    }

    /* =====================================================
     | Hybrid responder (JSON or HTML)
     ===================================================== */

    protected function respond(
        string $redirect,
        mixed $data = null,
        string $message = '',
        int $status = 200
    ): void {
        if ($this->wantsJson()) {
            ApiResponse::success($this->response, $data, $message, $status, $redirect);
            return;
        }

        $this->redirectAfterPost($redirect);
    }

    /* =====================================================
     | View rendering
     ===================================================== */

    protected function render(string $view, array $data = [], ?string $layout = null): never
    {
        $html = ThemeManager::instance()->render($view, $data, $layout);
        $this->response->html($html)->emitAndExit();
    }

    /* =====================================================
     | Exception handling
     ===================================================== */

    protected function handleException(Throwable $e, int $status = 500): never
    {
        error_log($e->getMessage());

        if ($this->wantsJson()) {
            ApiResponse::error(
                $this->response,
                'An unexpected error occurred.',
                $status
            );
        }

        $this->response->text('Internal Server Error', $status)->emitAndExit();
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

    /* =====================================================
     | Your existing upload logic stays here
     ===================================================== */
    /**
     * POST /api/upload_chunk
     */
    protected function upload_chunk(): void
    {
        try {
            // Gather inputs: prefer framework request helpers, fallback to $_POST
            $fileId   = (string) ($this->request->input('fileId') ?? $_POST['fileId'] ?? '');
            $index    = isset($_POST['index']) ? intval($_POST['index']) : intval($this->request->input('index') ?? 0);
            $total    = isset($_POST['total']) ? intval($_POST['total']) : intval($this->request->input('total') ?? 0);
            $filename = (string) ($this->request->input('filename') ?? $_POST['filename'] ?? ($_FILES['chunk']['name'] ?? 'unknown'));
            $mimeType = (string) ($this->request->input('mimeType') ?? $_POST['mimeType'] ?? ($_FILES['chunk']['type'] ?? 'application/octet-stream'));

            // Optionally allow overriding storage paths via config
            $tempBase = defined('BASEPATH') ? BASEPATH . '/storage/uploads/tmp_chunks' : __DIR__ . '/../../storage/uploads/tmp_chunks';

            $options = [
                'tempBase' => $tempBase,
                'fileId'   => $fileId,
                'index'    => $index,
                'total'    => $total,
                'filename' => $filename,
                'mimeType' => $mimeType
                // NOTE: chunk file is read from $_FILES['chunk'] inside handleUploadChunk
            ];

            $result = $this->handleUploadChunk($options);

            if (!empty($result['status']) && $result['status'] === true) {
                // standard success envelope
                $this->success([
                    'fileId' => $result['fileId'] ?? $fileId,
                    'index'  => $result['index'] ?? $index,
                    'message'=> $result['message'] ?? 'Chunk stored'
                ], 200);
                return;
            }

            // validation / store error
            //$this->jsonErrorResponse($result['message'] ?? 'Failed to store chunk', 400);
            ApiResponse::error($this->response, $result['message'] ?? 'Failed to store chunk', 400, [], 'Bad Request');
            return;

        } catch (Exception $e) {
            //$this->handleException($e, 500);
            error_log($e->getMessage());
            return;
        }
    }

    /**
     * POST /api/upload_complete
     */
    protected function upload_complete(): void
    {
        try {
            // Accept either JSON body or form params
            $raw = (string) file_get_contents('php://input');
            $body = @json_decode($raw, true);
            if (!is_array($body)) $body = $_POST;

            $fileId   = isset($body['fileId']) ? (string)$body['fileId'] : '';
            $filename = $body['filename'] ?? null;
            $total    = isset($body['total']) ? intval($body['total']) : null;

            if ($fileId === '') {
                ApiResponse::error($this->response, 'Missing fileId', 400, [], 'Bad Request', []);
                return;
            }

            $tempBase    = defined('BASEPATH') ? BASEPATH . '/storage/uploads/tmp_chunks' : __DIR__ . '/../../storage/uploads/tmp_chunks';
            $uploadsBase = defined('BASEPATH') ? BASEPATH . '/storage/uploads/final' : __DIR__ . '/../../storage/uploads/final';
            $maxSize     = (50 * 1024 * 1024);

            $options = [
                'tempBase'    => $tempBase,
                'uploadsBase' => $uploadsBase,
                'maxSize'     => $maxSize,
                'fileId'      => $fileId,
                'filename'    => $filename,
                'total'       => $total
            ];

            $result = $this->handleUploadComplete($options);

            if (!empty($result['status']) && $result['status'] === true) {
                // Return assembled file info
                $this->success([
                    'fileId'   => $result['fileId'],
                    'filename' => basename($result['path']),
                    'path'     => $result['path'],
                    'size'     => $result['size'],
                    'message'  => $result['message'] ?? 'File assembled'
                ], 200);
                return;
            }

            //$this->jsonErrorResponse($result['message'] ?? 'Failed to assemble file', 400);
            ApiResponse::error($this->response, $result['message'] ?? 'Failed to assemble file', 400, [], 'Bad Request', []);
            return;

        } catch (Exception $e) {            
            error_log($e->getMessage());
            //$this->handleException($e, 500);
            return;
        }
    }

    
    /* ---------------------------------------------------------------------
     * Upload helpers (kept compatible with your helper approach)
     * --------------------------------------------------------------------- */

    protected function handleUploadChunk(array $params = []): void
    {
        // Expecting chunk upload fields: fileId, index, total, and $_FILES['chunk']
        $fileId = (string)($params['fileId'] ?? '');
        $index  = (int)($params['index'] ?? -1);
        $total  = (int)($params['total'] ?? 0);

        if ($fileId === '' || $index < 0 || $total <= 0 || empty($_FILES['chunk'])) {
            $this->error('Invalid upload chunk parameters', 400,[],'Bad Request');
        }

        try {
            $tmpDir = PathHelper::join($params['tempBase'], 'yantra_uploads', $fileId);// PathHelper::join(sys_get_temp_dir(), 'yantra_uploads', $fileId);
            $this->ensureDir($tmpDir);

            UploadHelper::saveChunk($tmpDir, $fileId, $index, $_FILES['chunk']);
            $this->success(['fileId' => $fileId, 'index' => $index], 200);
        } catch (Exception $e) {
            $this->error('Chunk upload failed', 500, [], 'upload_failed');
        }
    }

    protected function handleUploadComplete(array $params = []): void
    {
        global $app;
        $fileId   = (string)($params['fileId'] ?? '');
        $filename = (string)($params['filename'] ?? 'upload.bin');
        $total    = (int)($params['total'] ?? 0);

        if ($fileId === '' || $total <= 0) {
            $this->error('Invalid upload completion parameters', 400,[], 'upload_invalid');
        }

        try {
            $tmpDir =PathHelper::join($params['tempBase'], 'yantra_uploads', $fileId);// PathHelper::join(sys_get_temp_dir(), 'yantra_uploads', $fileId);
            $destDir = PathHelper::join($params['uploadsBase'], 'uploads');//PathHelper::join($app->getBasePath(), 'storage', 'uploads');
            $this->ensureDir($destDir);

            $destPath = PathHelper::join($destDir, $filename);

            UploadHelper::assembleChunks($tmpDir, $destPath,$fileId,$filename, $total);

            $this->success(['path' => $destPath], 200);
        } catch (Exception $e) {
            
            $this->error('Upload completion failed', 500, [], 'upload_failed');
        }
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
