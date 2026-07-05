<?php
declare(strict_types=1);

namespace System\Services\Email\Contracts;

use System\Services\Email\TemplateResult;

interface TemplateRendererInterface
{
    /**
     * Render a template into subject/html/text fragments.
     *
     * $template is renderer-specific:
     * - a file path (PhpTemplateRenderer)
     * - a logical name/key (your custom renderer)
     */
    public function render(string $template, array $data = []): TemplateResult;
}
