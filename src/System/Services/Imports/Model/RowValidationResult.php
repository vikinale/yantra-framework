<?php
declare(strict_types=1);

namespace System\Services\Imports\Model;

final class RowValidationResult
{
    /** @param ImportError[] $errors */
    public function __construct(public bool $ok, public array $errors = []) {}

    public static function ok(): self { return new self(true, []); }

    /** @param ImportError[] $errors */
    public static function fail(array $errors): self { return new self(false, $errors); }
}
