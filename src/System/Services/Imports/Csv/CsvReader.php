<?php
declare(strict_types=1);

namespace System\Services\Imports\Csv;

final class CsvReader implements \IteratorAggregate
{
    public function __construct(
        private string $path,
        private string $delimiter = ',',
        private string $enclosure = '"',
        private string $escape = '\\',
        private bool $hasHeader = true
    ) {}

    /** @return \Traversable<int, array<int,string>> */
    public function getIterator(): \Traversable
    {
        $fh = fopen($this->path, 'rb');
        if ($fh === false) throw new \RuntimeException("Cannot open CSV: {$this->path}");
        $n=0;
        while (($row = fgetcsv($fh, 0, $this->delimiter, $this->enclosure, $this->escape)) !== false) {
            $n++;
            $row = array_map(fn($v) => $v === null ? '' : (string)$v, $row);
            yield $n => $row;
        }
        fclose($fh);
    }

    public function hasHeader(): bool { return $this->hasHeader; }
}
