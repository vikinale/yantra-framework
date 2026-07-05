<?php
declare(strict_types=1);

namespace System\Services\Reporting;

use System\Services\Reporting\Definition\ReportDefinitionInterface;
use System\Services\Reporting\Exceptions\ReportExecutionException;
use System\Services\Reporting\Params\ParamCaster;
use System\Services\Reporting\Params\ParamValidator;
use System\Services\Reporting\Security\ReportAuthorizerInterface;

final class ReportManager
{
    public function __construct(
        private ReportRegistry $registry,
        private ParamCaster $caster = new ParamCaster(),
        private ParamValidator $validator = new ParamValidator(),
        private ?ReportAuthorizerInterface $authorizer = null
    ) {
    }

    public function registry(): ReportRegistry
    {
        return $this->registry;
    }

    /**
     * List reports (metadata only), optionally filtered by authorizer.
     *
     * @return list<array<string, mixed>>
     */
    public function list(ReportContext $ctx): array
    {
        $out = [];
        foreach ($this->registry->all() as $report) {
            if ($this->authorizer && !$this->authorizer->canView($ctx, $report)) {
                continue;
            }
            $out[] = $report->metadata()->toArray();
        }
        return $out;
    }

    /**
     * Run a report and return a UI-agnostic result.
     *
     * @param array<string, mixed> $input raw input from request/UI
     */
    public function run(string $key, ReportContext $ctx, array $input): ReportResult
    {
        $report = $this->registry->get($key);

        if ($this->authorizer && !$this->authorizer->canRun($ctx, $report)) {
            // Auth is app-defined; choose an exception strategy.
            // Reuse validation exception shape? Better to throw a plain RuntimeException.
            throw new \RuntimeException("Not authorized to run report: {$key}");
        }

        $schema = $report->schema();
        $typed = $this->caster->cast($schema, $input, $ctx);
        $this->validator->validateOrThrow($schema, $typed, $ctx, $input);

        $t0 = microtime(true);
        try {
            $result = $report->run($ctx, $typed);
        } catch (\Throwable $e) {
            throw new ReportExecutionException($key, $e);
        }
        $elapsedMs = (int)round((microtime(true) - $t0) * 1000);

        // Merge meta without mutating ReportResult: create a new instance
        $meta = $result->meta;
        $meta['report_key'] = $key;
        $meta['report_version'] = $report->metadata()->version;
        $meta['elapsed_ms'] = $elapsedMs;
        if ($report->metadata()->cacheTtlSeconds !== null) {
            $meta['cache_ttl_seconds'] = $report->metadata()->cacheTtlSeconds;
        }

        return new ReportResult(
            columns: $result->columns,
            rows: $result->rows,
            summary: $result->summary,
            meta: $meta
        );
    }

    public function getDefinition(string $key): ReportDefinitionInterface
    {
        return $this->registry->get($key);
    }
}
