<?php
declare(strict_types=1);

namespace System\Http;

use System\Core\Response as CoreResponse;
use System\Hooks;

/**
 * Response facade.
 *
 * Core-only wrapper around the immutable System\Core\Response.
 *
 * Notes:
 * - Theme/view/page rendering has been removed from the framework core.
 * - Applications may build their own view layer on top of CoreResponse.
 */
final class Response
{
    private CoreResponse $res;

    public function __construct(?\Psr\Http\Message\ResponseInterface $psr = null)
    {
        $this->res = $psr ? new CoreResponse($psr) : new CoreResponse();
    }

    public function setStatus(int $code): void
    {
        $this->res = $this->res->withStatus($code);
        Hooks::do_action('http_response_status', $code);
    }

    public function redirect(string $url, int $code = 302): void
    {
        if (!preg_match('~^https?://~i', $url) && !str_starts_with($url, '/')) {
            $url = '/' . ltrim($url, '/');
        }

        if (preg_match('/^(javascript:|data:)/i', $url)) {
            throw new \InvalidArgumentException('Invalid redirect URL.');
        }

        // Build full URL if needed
        if (!preg_match('~^https?://~i', $url)) {
            $base = rtrim((string) Config::get('app.base_url', ''), '/');
            $url  = $base !== '' ? $base . $url : $url;
        }

        $this->res = $this->res
            ->withHeader('Location', $url)
            ->withStatus($code);

        Hooks::do_action('http_response_redirect', $code, $url);
        $this->res->emitAndExit();
    }

    public function sendJson(array $payload, int $status = 200): void
    {
        $psr = $this->res->json($payload, $status);
        $wrapped = new CoreResponse($psr);
        Hooks::do_action('http_response_json', $status);
        $wrapped->emitAndExit();
    }

    public function file_download(string $filepath, ?string $filename = null): void
    {
        $this->res = $this->res->file($filepath, $filename);
        Hooks::do_action('http_response_file_download', $this->res->getStatusCode(), $filepath);
        $this->res->emitAndExit();
    }

    public function getCoreResponse(): CoreResponse
    {
        return $this->res;
    }

    public function __get(string $name)
    {
        if ($name === 'headers') {
            return $this->res->getPsr7()->getHeaders();
        }
        if ($name === 'statusCode') {
            return $this->res->getStatusCode();
        }
        return null;
    }

    public function emitAndExit(): void
    {
        $this->res->emitAndExit();
    }

    public function emit(): void
    {
        $this->res->emit();
    }
}
