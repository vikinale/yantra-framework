<?php
declare(strict_types=1);

namespace System\Services\Email;

final class Address
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $name = null
    ) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email address: {$email}");
        }
    }

    public function format(): string
    {
        if ($this->name === null || $this->name === '') {
            return $this->email;
        }
        $name = self::encodeHeaderIfNeeded($this->name);
        return sprintf('%s <%s>', $name, $this->email);
    }

    private static function encodeHeaderIfNeeded(string $value): string
    {
        // Use mb_encode_mimeheader if available for non-ascii.
        if (preg_match('/[\x80-\xFF]/', $value) && function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        }
        // Quote if contains special chars.
        if (preg_match('/[",<>;]/', $value)) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }
        return $value;
    }
}
