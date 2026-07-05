<?php
declare(strict_types=1);

namespace System\View;

/**
 * EscapedData
 *
 * Wraps data to ensure string output is automatically escaped.
 * Provides raw() method to access original unsafe data.
 */
class EscapedData implements \ArrayAccess, \IteratorAggregate, \Countable, \Stringable
{
    private mixed $data;

    public function __construct(mixed $data)
    {
        $this->data = $data;
    }

    public function raw(): mixed
    {
        return $this->data;
    }

    public function __toString(): string
    {
        if (is_null($this->data)) {
            return '';
        }
        if (is_string($this->data) || is_numeric($this->data)) {
            return htmlspecialchars((string) $this->data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        // If it's an object with __toString, escape its output too (unless it's already safe)
        if (is_object($this->data) && method_exists($this->data, '__toString')) {
             return htmlspecialchars((string) $this->data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        // Non-stringable object: never silently drop data. Emit an escaped, visible
        // debug marker so the output is safe yet clearly signals a rendering mistake.
        if (is_object($this->data)) {
            return htmlspecialchars('[object ' . get_class($this->data) . ']', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        // Booleans stringify to '' (false) or '1' (true) via (string) cast, which hides
        // data; emit an explicit, escaped representation instead.
        if (is_bool($this->data)) {
            return $this->data ? 'true' : 'false';
        }

        // Arrays cannot be cast to string safely; surface a visible, escaped marker
        // rather than dropping the value or triggering an "Array to string" warning.
        if (is_array($this->data)) {
            return htmlspecialchars('[array]', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        // Any remaining type (e.g. resource): escape its stringified form rather than
        // dropping it entirely.
        return htmlspecialchars((string) $this->data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ArrayAccess implementation to wrap children
    public function offsetExists(mixed $offset): bool
    {
        return is_array($this->data) && array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!$this->offsetExists($offset)) {
            return null;
        }
        $val = $this->data[$offset];
        // Recursive wrapping
        return new self($val);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \RuntimeException('EscapedData is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \RuntimeException('EscapedData is immutable.');
    }

    // IteratorAggregate implementation
    public function getIterator(): \Traversable
    {
        if (is_array($this->data) || $this->data instanceof \Traversable) {
            foreach ($this->data as $k => $v) {
                yield $k => new self($v);
            }
        }
    }

    public function count(): int
    {
        return is_array($this->data) || $this->data instanceof \Countable ? count($this->data) : 0;
    }
}
