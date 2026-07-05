<?php
declare(strict_types=1);

namespace System\Services\Reporting;

use System\Services\Reporting\Definition\ReportDefinitionInterface;
use System\Services\Reporting\Definition\ReportMetadata;
use System\Services\Reporting\Exceptions\ReportNotFoundException;
use RuntimeException;

final class ReportRegistry
{
    /** @var array<string, ReportDefinitionInterface> */
    private array $reports = [];

    public function add(ReportDefinitionInterface $report): void
    {
        $key = $report->metadata()->key;
        if (isset($this->reports[$key])) {
            throw new RuntimeException("Report already registered: {$key}");
        }
        $this->reports[$key] = $report;
    }

    /**
     * @param iterable<ReportDefinitionInterface> $reports
     */
    public function addMany(iterable $reports): void
    {
        foreach ($reports as $r) {
            $this->add($r);
        }
    }

    public function has(string $key): bool
    {
        return isset($this->reports[$key]);
    }

    public function get(string $key): ReportDefinitionInterface
    {
        if (!isset($this->reports[$key])) {
            throw new ReportNotFoundException($key);
        }
        return $this->reports[$key];
    }

    /**
     * @return list<ReportDefinitionInterface>
     */
    public function all(): array
    {
        return array_values($this->reports);
    }

    /**
     * @return list<ReportMetadata>
     */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->reports as $r) {
            $out[] = $r->metadata();
        }
        return $out;
    }
}
