<?php
declare(strict_types=1);

namespace System\Validation;

use System\Validation\Util\Arr;

final class ValidationResult
{
    /**
     * @param array<string,mixed> $validatedFlat
     * @param array<string,mixed> $validatedNested
     */
    public function __construct(
        private bool $ok,
        private ErrorBag $errors,
        private array $validatedFlat,
        private array $validatedNested
    ) {}

    public function ok(): bool { return $this->ok; }
    public function errors(): ErrorBag { return $this->errors; }

    /**
     * Contains only keys that had rules defined. Keys may include dot-notation.
     *
     * @return array<string,mixed>
     */
    public function validated(): array { return $this->validatedFlat; }

    /**
     * Nested version of validated data.
     *
     * @return array<string,mixed>
     */
    public function validatedNested(): array { return $this->validatedNested; }
}
