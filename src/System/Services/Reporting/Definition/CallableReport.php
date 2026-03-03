<?php
declare(strict_types=1);

namespace System\Services\Reporting\Definition;

use InvalidArgumentException;
use System\Services\\Reporting\Params\ParamSchema;
use System\Services\\Reporting\ReportContext;
use System\Services\\Reporting\ReportResult;

/**
 * Convenience implementation for most application reports.
 */
final class CallableReport implements ReportDefinitionInterface
{
    private ReportMetadata $metadata;
    private ParamSchema $schema;

    /**
     * @var \Closure(ReportContext, array<string, mixed>): ReportResult
     */
    private \Closure $runner;

    /**
     * @param \Closure(ReportContext, array<string, mixed>): ReportResult $runner
     */
    public function __construct(ReportMetadata $metadata, ParamSchema $schema, \Closure $runner)
    {
        $this->metadata = $metadata;
        $this->schema = $schema;
        $this->runner = $runner;
    }

    public function metadata(): ReportMetadata
    {
        return $this->metadata;
    }

    public function schema(): ParamSchema
    {
        return $this->schema;
    }

    public function run(ReportContext $ctx, array $params): ReportResult
    {
        $result = ($this->runner)($ctx, $params);
        if (!$result instanceof ReportResult) {
            throw new InvalidArgumentException('Report runner must return ReportResult');
        }
        return $result;
    }
}
