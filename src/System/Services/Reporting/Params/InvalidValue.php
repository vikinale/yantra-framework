<?php
declare(strict_types=1);

namespace System\Services\Reporting\Params;

/**
 * Marker for "value was provided but could not be cast".
 *
 * This allows validation to distinguish "missing" from "invalid".
 */
final class InvalidValue
{
    public function __construct(
        public readonly mixed $raw,
        public readonly string $reason = 'invalid'
    ) {
    }
}
