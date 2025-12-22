<?php
declare(strict_types=1);

namespace Core;

use Exception;
use System\Controllers\Controller;
use System\Core\Response as CoreResponse;
use System\Http\Request;
use System\Http\Response;

/**
 * BaseController
 *
 * Yantra framework base for all Core & App controllers.
 */
abstract class BaseController extends Controller
{

    /** RFC 9110 reason phrases (subset) */
    protected array $statusPhrases = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
    }

    
    /* ========================================================================
     *  JSON helpers
     * ====================================================================== */

    protected function jsonSuccess(string $message, array $data = []): void
    {
        $this->response->sendJson([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ]);
    }

    protected function jsonError(string $message, array $data = []): void
    {
        $this->response->sendJson([
            'status'  => 'error',
            'message' => $message,
            'data'    => $data,
        ], 400);
    }

    protected function jsonValidationError(array $errors, string $message): void
    {
        $this->response->sendJson([
            'status'  => 'error',
            'message' => $message,
            'data'    => ['errors' => $errors]
        ]);
    }

    
    /**
     * Send a JSON response using the PSR-aware response pipeline.
     *
     * @param mixed $data
     * @param int $statusCode
     * @param array $headers
     */
    protected function jsonResponse(mixed $data, int $statusCode = 200, array $headers = []): void
    {
        $payload = [
            'status'  => $statusCode,
            'message' => $this->statusPhrases[$statusCode] ?? null,
            'data'    => $data,
        ];

        // Prefer Response wrapper sendJson if present.
        if (isset($this->response) && method_exists($this->response, 'sendJson')) {
            // sendJson should handle status code
            $this->response->sendJson($payload, $statusCode);
            return;
        }

        // Otherwise use PSR response and attach headers.
        $psr = $this->response->getCoreResponse()->json($payload, $statusCode);
        foreach ($headers as $k => $v) {
            $psr = $psr->withHeader($k, (string)$v);
        }

        $this->emitPsrResponse($psr, true);
    }
    
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
                ], $result['message'] ?? 'Chunk stored', 200);
                return;
            }

            // validation / store error
            $this->jsonErrorResponse($result['message'] ?? 'Failed to store chunk', 400);
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
                $this->jsonErrorResponse('Missing fileId', 400);
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
                ], $result['message'] ?? 'File assembled', 200);
                return;
            }

            $this->jsonErrorResponse($result['message'] ?? 'Failed to assemble file', 400);
            return;

        } catch (Exception $e) {            
            error_log($e->getMessage());
            //$this->handleException($e, 500);
            return;
        }
    }
}
