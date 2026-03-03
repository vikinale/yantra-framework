<?php
declare(strict_types=1);

namespace System\Services\Reporting\Definition;

use System\Services\\Reporting\Params\ParamSchema;
use System\Services\\Reporting\ReportContext;
use System\Services\\Reporting\ReportResult;

interface ReportDefinitionInterface
{
    public function metadata(): ReportMetadata;

    public function schema(): ParamSchema;

    /**
     * Execute the report.
     *
     * The application controls all data access (DB/ORM/APIs).
     * The reporting Service only standardizes params and output shape.
     *
     * @param array<string, mixed> $params Typed params produced by ParamCaster
     */
    public function run(ReportContext $ctx, array $params): ReportResult;
}
