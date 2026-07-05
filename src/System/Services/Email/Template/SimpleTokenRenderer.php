<?php
declare(strict_types=1);

namespace System\Services\Email\Template;

use System\Services\Email\Contracts\TemplateRendererInterface;
use System\Services\Email\TemplateResult;

/**
 * Minimal token renderer for simple string templates:
 * - Replaces {{key}} with values from $data (scalar only).
 * - Useful for quick templates or testing.
 *
 * Provide templates as arrays:
 * [
 *   'welcome' => ['subject' => 'Hi {{name}}', 'html' => '...', 'text' => '...'],
 * ]
 */
final class SimpleTokenRenderer implements TemplateRendererInterface
{
    /** @param array<string,array{subject:string,html?:string,text?:string,meta?:array}> $templates */
    public function __construct(private array $templates) {}

    public function render(string $template, array $data = []): TemplateResult
    {
        if (!isset($this->templates[$template])) {
            throw new \InvalidArgumentException("Unknown template key: {$template}");
        }
        $tpl = $this->templates[$template];

        $subject = $this->replaceTokens($tpl['subject'], $data);
        $html = isset($tpl['html']) ? $this->replaceTokens($tpl['html'], $data) : null;
        $text = isset($tpl['text']) ? $this->replaceTokens($tpl['text'], $data) : null;
        $meta = isset($tpl['meta']) && is_array($tpl['meta']) ? $tpl['meta'] : [];

        return new TemplateResult($subject, $html, $text, $meta);
    }

    private function replaceTokens(string $s, array $data): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', function($m) use ($data) {
            $key = $m[1];
            $v = $data[$key] ?? '';
            if (is_scalar($v) || $v === null) return (string)$v;
            return '';
        }, $s) ?? $s;
    }
}
