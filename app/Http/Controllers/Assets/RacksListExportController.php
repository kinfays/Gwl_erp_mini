<?php

namespace App\Http\Controllers\Assets;

use App\Models\IctRack;
use App\Services\Assets\AssetListColumns;
use App\Services\Assets\AssetListQuery;
use App\Services\Assets\RackService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The Racks list as Excel or PDF: the rows its filters leave, in the columns ticked (?cols=). Scoped through the same
 * viewing rule as the list, so a regional ICT user only exports their own region's racks. The route holds the export
 * permission (assets.export_inventory); the list screen itself needs assets.view_racks, checked here too.
 */
class RacksListExportController extends AssetListExportController
{
    protected function listKey(): string
    {
        return AssetListColumns::RACKS;
    }

    protected function title(): string
    {
        return 'Racks';
    }

    protected function slug(): string
    {
        return 'racks';
    }

    protected function table(): string
    {
        return 'ict_racks';
    }

    protected function listRoute(): string
    {
        return 'assets.racks';
    }

    /** The base filters (q, status, ...) plus the racks' own: `districts`, cut down to what this user may see. */
    protected function filters(Request $request): array
    {
        abort_unless(RackService::mayView($request->user()), 403, 'You do not have permission to view racks.');

        $filters = parent::filters($request);

        // Only a status a rack can have is a filter; the other parent filters (type, ups, age...) do not apply to racks.
        $filters['status'] = in_array($filters['status'] ?? '', IctRack::STATUSES, true) ? $filters['status'] : '';
        $filters['districts'] = $this->permittedDistrictIds(Arr::wrap($request->query('districts')));

        return array_filter($filters, fn ($value, $key) => in_array($key, ['search', 'status', 'districts'], true), ARRAY_FILTER_USE_BOTH);
    }

    protected function query(array $filters): Builder
    {
        return app(AssetListQuery::class)->racks(
            $this->scopeAssetsForViewing(IctRack::query()->with(AssetListColumns::relations(AssetListColumns::RACKS))),
            $filters
        )->orderBy('ict_racks.name')->orderBy('ict_racks.id');
    }
}
