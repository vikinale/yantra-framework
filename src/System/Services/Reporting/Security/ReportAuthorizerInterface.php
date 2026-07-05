<?php
declare(strict_types=1);

namespace System\Services\Reporting\Security;

use System\Services\Reporting\Definition\ReportDefinitionInterface;
use System\Services\Reporting\ReportContext;

/**
 * Optional authorization hook.
 * The application owns auth/permissions; the Service only calls into this interface.
 */
interface ReportAuthorizerInterface
{
    /** Return true if the report should appear in listings/catalogs for this context. */
    public function canView(ReportContext $ctx, ReportDefinitionInterface $report): bool;

    /** Return true if the report can be executed for this context. */
    public function canRun(ReportContext $ctx, ReportDefinitionInterface $report): bool;
}
