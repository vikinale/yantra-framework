<?php
declare(strict_types=1);

namespace System\Testing\Runner;

class ReporterJson
{
    private array $results = [];
    private string $path;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->path = $path;
    }

    public function log(array $data): void
    {
        $this->results[] = $data;
    }

    public function save(): void
    {
        file_put_contents($this->path, json_encode($this->results, JSON_PRETTY_PRINT));
    }
}
