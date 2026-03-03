<?php
declare(strict_types=1);

namespace System\Testing\Runner;

class ReporterCsv
{
    private $handle;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->handle = fopen($path, 'w');
        fputcsv($this->handle, [
            'run_id', 'test_class', 'test_name', 'case_id', 'title', 'status', 'duration_ms', 'error', 'meta_json'
        ]);
    }

    public function log(string $runId, string $class, string $name, string $caseId, string $title, string $status, float $duration, ?string $error, array $meta): void
    {
        fputcsv($this->handle, [
            $runId,
            $class,
            $name,
            $caseId,
            $title,
            $status,
            number_format($duration, 2, '.', ''),
            $error ?? '',
            json_encode($meta, JSON_UNESCAPED_SLASHES)
        ]);
    }

    public function close(): void
    {
        if ($this->handle) {
            fclose($this->handle);
        }
    }
}
