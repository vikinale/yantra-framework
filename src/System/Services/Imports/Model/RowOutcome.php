<?php
declare(strict_types=1);

namespace System\Services\Imports\Model;

final class RowOutcome
{
    public function __construct(public bool $ok, public ?RollbackRecord $rollback = null, public array $meta = []) {}

    public static function success(?RollbackRecord $rollback = null, array $meta = []): self
    {
        return new self(true, $rollback, $meta);
    }

    public static function failure(array $meta = []): self
    {
        return new self(false, null, $meta);
    }
}
