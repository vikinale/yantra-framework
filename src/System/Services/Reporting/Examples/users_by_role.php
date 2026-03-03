<?php
declare(strict_types=1);

use System\Services\\Reporting\Definition\CallableReport;
use System\Services\\Reporting\Definition\ReportMetadata;
use System\Services\\Reporting\Params\ParamDefinition;
use System\Services\\Reporting\Params\ParamSchema;
use System\Services\\Reporting\Params\ParamType;
use System\Services\\Reporting\Pagination;
use System\Services\\Reporting\ReportContext;
use System\Services\\Reporting\ReportResult;

// Example report definition file.
// Your application can `require` this and add it to a ReportRegistry.

return new CallableReport(
    metadata: new ReportMetadata(
        key: 'users_by_role',
        title: 'Users by Role',
        description: 'Lists users filtered by role',
        category: 'Users',
        tags: ['users'],
        permissions: ['reports.users.view'],
        cacheTtlSeconds: 60,
        version: '1'
    ),
    schema: (new ParamSchema([
        new ParamDefinition('role', ParamType::ENUM, required: true, allowed: ['admin', 'staff', 'user']),
    ]))->withPagination(50, 500),
    runner: function(ReportContext $ctx, array $params): ReportResult {
        $repo = $ctx->get('userRepo');
        $pagination = Pagination::fromParams($params);

        // App decides implementation: DB/ORM/API, etc.
        $rows = $repo->fetchUsersByRole($params['role'], $pagination->limit, $pagination->offset);

        return new ReportResult(
            columns: [
                ['key' => 'id', 'label' => 'ID', 'type' => 'int'],
                ['key' => 'name', 'label' => 'Name', 'type' => 'string'],
                ['key' => 'email', 'label' => 'Email', 'type' => 'string'],
            ],
            rows: $rows,
            summary: [],
            meta: $pagination->toMeta()
        );
    }
);
