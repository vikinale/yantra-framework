<?php
declare(strict_types=1);

namespace Core;

use System\Http\Request;
use System\Http\Response;
use System\Session;
use System\Exceptions\YantraException;

/**
 * BaseService
 *
 * Common base for all framework & app services.
 * - Holds shared dependencies (Request/Response/Session)
 * - Provides small validation + error-wrapping helpers
 */
abstract class BaseService
{
    protected ?Request  $request;
    protected ?Response $response;
    protected ?Session  $session;

    public function __construct(
        ?Request $request = null,
        ?Response $response = null,
        ?Session $session = null
    ) {
        $this->request  = $request;
        $this->response = $response;
        $this->session  = $session;
    }

    /**
     * Ensure an ID is a positive integer.
     *
     * @throws YantraException
     */
    protected function ensurePositiveId(int $id, string $fieldName = 'id'): void
    {
        if ($id <= 0) {
            throw YantraException::validation(
                sprintf('Invalid %s.', $fieldName),
                [
                    'field' => $fieldName,
                    'value' => $id,
                ]
            );
        }
    }

    /**
     * Wrap low-level exceptions into a consistent YantraException::internal
     * to keep a unified error surface for controllers.
     *
     * @throws YantraException
     */
    protected function wrapInternalError(
        \Throwable $e,
        string $message = 'Internal error.',
        array $context = []
    ): never {
        $context['previous_message'] = $e->getMessage();
        $context['previous_code']    = $e->getCode();

        throw YantraException::internal($message, $context, $e);
    }
}
