<?php
declare(strict_types=1);

namespace System\Testing\Data;

class DataSet
{
    protected array $rows = [];

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public static function rows(array $rows): self
    {
        return new self($rows);
    }

    public static function create(array $rows): self
    {
        return new self($rows);
    }

    public static function csv(string $path): self
    {
        return new CsvDataSet($path);
    }

    public function getRows(): array
    {
        return $this->rows;
    }
}
