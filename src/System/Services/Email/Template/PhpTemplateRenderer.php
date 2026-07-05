<?php
declare(strict_types=1);

namespace System\Services\Email\Template;

use System\Services\Email\Contracts\TemplateRendererInterface;
use System\Services\Email\Exceptions\TemplateException;
use System\Services\Email\TemplateResult;

/**
 * Renders PHP templates from disk.
 *
 * Template file should return an array:
 * [
 *   'subject' => '...',
 *   'html' => '<p>..</p>', // optional
 *   'text' => '...',       // optional
 *   'meta' => [...],       // optional
 * ]
 */
final class PhpTemplateRenderer implements TemplateRendererInterface
{
    public function __construct(private string $baseDir) {}

    public function render(string $template, array $data = []): TemplateResult
    {
        $path = $this->resolve($template);
        $vars = $data;
        $result = (static function (string $__path, array $__vars) {
            extract($__vars, EXTR_SKIP);
            /** @noinspection PhpIncludeInspection */
            return require $__path;
        })($path, $vars);

        if (!is_array($result) || !isset($result['subject'])) {
            throw new TemplateException("Email template must return array with key 'subject': {$path}");
        }

        return new TemplateResult(
            subject: (string)$result['subject'],
            html: isset($result['html']) ? (string)$result['html'] : null,
            text: isset($result['text']) ? (string)$result['text'] : null,
            meta: isset($result['meta']) && is_array($result['meta']) ? $result['meta'] : []
        );
    }

    private function resolve(string $template): string
    {
        $template = str_replace(['..', '\\'], ['', '/'], $template);
        $path = rtrim($this->baseDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($template, '/\\');
        if (!str_ends_with($path, '.php')) $path .= '.php';
        if (!is_file($path)) {
            throw new TemplateException("Template not found: {$path}");
        }
        return $path;
    }
}
