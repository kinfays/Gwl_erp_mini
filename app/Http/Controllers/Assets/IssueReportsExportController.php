<?php

namespace App\Http\Controllers\Assets;

use App\Models\IctAssetIssueReport;
use App\Services\Assets\AssetListColumns;
use App\Services\Assets\AssetListQuery;
use Illuminate\Database\Eloquent\Builder;

class IssueReportsExportController extends AssetListExportController
{
    protected function listKey(): string
    {
        return AssetListColumns::REPORTS;
    }

    protected function title(): string
    {
        return 'Issue reports';
    }

    protected function slug(): string
    {
        return 'issue_reports';
    }

    protected function table(): string
    {
        return 'ict_asset_issue_reports';
    }

    protected function listRoute(): string
    {
        return 'assets.reports';
    }

    protected function query(array $filters): Builder
    {
        return app(AssetListQuery::class)->reports(
            $this->scopeReportsForViewing(IctAssetIssueReport::query()->with(AssetListColumns::relations(AssetListColumns::REPORTS))),
            $filters
        )->latest()->orderByDesc('id');
    }
}
