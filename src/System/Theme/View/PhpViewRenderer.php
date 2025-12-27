<?php
declare(strict_types=1);

namespace System\Theme\View;

use RuntimeException;

final class PhpViewRenderer
{
    /**
     * Render a PHP template file with isolated scope.
     * Variables are available via $ctx (ViewContext), $content (layout slot).
     */
    public function renderFile(string $file, ViewContext $ctx, ?string $content = null): string
    {
        if (!is_file($file)) {
            throw new RuntimeException("View file not found: {$file}");
        }

        $render = static function (string $__file, ViewContext $__ctx, ?string $__content): string {
            $ctx = $__ctx;
            $content = $__content;

            ob_start();
            include $__file;
            return (string) ob_get_clean();
        };

        return $render($file, $ctx, $content);
    }
}
