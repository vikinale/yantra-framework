<?php
declare(strict_types=1);

namespace System\Services\Email;

final class TemplateResult
{
    public function __construct(
        public readonly string $subject,
        public readonly ?string $html = null,
        public readonly ?string $text = null,
        public readonly array $meta = []
    ) {}
}
