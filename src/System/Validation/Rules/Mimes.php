<?php
declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

final class Mimes implements RuleInterface
{
    public function __construct(private array $types) {}
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (!is_array($value) || !isset($value['tmp_name'])) return false;
        if (!is_uploaded_file($value['tmp_name'])) return false;
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($value['tmp_name']);
        return in_array($mime, $this->types, true);
    }
    public function message(string $field): string { return "{$field} type is invalid."; }
}
