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

        return '';
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
