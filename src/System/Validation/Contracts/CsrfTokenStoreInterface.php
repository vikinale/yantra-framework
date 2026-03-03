<?php
declare(strict_types=1);

namespace System\Validation\Contracts;

interface CsrfTokenStoreInterface
{
    public function get(string $key): ?string;
    public function set(string $key, string $token): void;
    public function delete(string $key): void;
}
