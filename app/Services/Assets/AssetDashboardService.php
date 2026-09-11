<?php

namespace App\Services\Assets;

use App\Models\IctAssetIssueReport;
use App\Models\IctAssetMaintenance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AssetDashboardService
{
    /**
     * Sub-type breakdown per dashboard KPI card. "asset_type" stays the
     * fine-grained vocabulary (App\Models\IctAsset::ASSET_TYPES); this is
     * just how those sub-types roll up into the six cards on the dashboard.
     */
    protected const CARD_TYPES = [
        'computers' => ['PC', 'AIO'],
        'laptops' => ['Laptop'],
        'printers' => ['PRT', 'PTC'],
        'phones' => ['POS', 'SIM', 'Ph'],
        'servers' => ['Server'],
        'network' => ['RT', 'SW', 'AP', 'MiFi', 'P2P', '4GRT'],
    ];

    protected const ALLOCATION_CARDS = ['computers', 'phones', 'network', 'printers'];

    protected const ALLOCATION_LABELS = [
        'computers' => 'Computers',
        'phones' => 'Phones',
        'network' => 'Network Devices',
        'printers' => 'Printers',
    ];

    /**
     * Categorical slots validated for colorblind separation against both the
     * light and dark chart surfaces. Emitted as CSS variables (see
     * dashboard.blade.php) so each theme gets its own validated step —
     * a dark-mode chart is a separate selection, not an automatic flip.
     * The donut legend direct-labels every slice, which is the required
     * relief for the two light-mode slots that sit below 3:1 contrast.
     */
    protected const ALLOCATION_COLORS = [
        'computers' => 'var(--assets-series-1)',
        'phones' => 'var(--assets-series-2)',
        'network' => 'var(--assets-series-3)',
        'printers' => 'var(--assets-series-4)',
    ];

    /**
     * @param Builder $scopedAssets an IctAsset query already region-scoped
     *                              for the current actor (see
     *                              ScopesAssetsByActor::scopeAssetsForActor)
     */
    public function build(Builder $scopedAssets): array
    {
        $cards = $this->buildCards($scopedAssets);
        $districtBreakdown = $this->buildDistrictBreakdown($scopedAssets);
        $allocation = $this->buildAllocation($cards);

        return [
            'cards' => $cards,
            'districtBreakdown' => $districtBreakdown,
            'allocation' => $allocation,
        ];
    }

    protected function buildCards(Builder $scopedAssets): array
    {
        $typeCounts = (clone $scopedAssets)
            ->select('asset_type', DB::raw('COUNT(*) as total'))
            ->groupBy('asset_type')
            ->pluck('total', 'asset_type');

        $cards = [];

        foreach (self::CARD_TYPES as $key => $types) {
            $badges = [];
            $total = 0;

            foreach ($types as $type) {
                $count = (int) ($typeCounts[$type] ?? 0);
                $badges[$type] = $count;
                $total += $count;
            }

            $cards[$key] = ['total' => $total, 'badges' => $badges];
        }

        return $cards;
    }

    /**
     * District "status" is derived, not stored: a district is flagged
     * ATTENTION when it has at least one open maintenance ticket for one of
     * its assets, or at least one open issue report raised against it.
     * Everything else reads OPTIMAL. This is the only signal available
     * today (IctAsset has no health/condition field of its own).
     */
    protected function buildDistrictBreakdown(Builder $scopedAssets): \Illuminate\Support\Collection
    {
        $rows = (clone $scopedAssets)
            ->leftJoin('districts', 'districts.id', '=', 'ict_assets.district_id')
            ->select(
                'ict_assets.district_id',
                DB::raw("COALESCE(districts.district_name, 'Unassigned') as district_name"),
                'ict_assets.asset_type',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('ict_assets.district_id', 'districts.district_name', 'ict_assets.asset_type')
            ->get();

        $districtIds = $rows->pluck('district_id')->filter()->unique()->values();

        $attentionDistricts = IctAssetMaintenance::query()
            ->join('ict_assets', 'ict_assets.id', '=', 'ict_asset_maintenances.ict_asset_id')
            ->where('ict_asset_maintenances.status', 'Open')
            ->whereIn('ict_assets.district_id', $districtIds)
            ->pluck('ict_assets.district_id')
            ->merge(
                IctAssetIssueReport::query()
                    ->where('status', 'Open')
                    ->whereIn('reporting_district_id', $districtIds)
                    ->pluck('reporting_district_id')
            )
            ->unique();

        return $rows
            ->groupBy('district_id')
            ->map(function ($districtRows, $districtId) use ($attentionDistricts) {
                $byType = $districtRows->pluck('total', 'asset_type');
                $categoryTotal = fn (array $types) => collect($types)->sum(fn ($type) => (int) ($byType[$type] ?? 0));

                return [
                    'district_id' => $districtId ?: null,
                    'district_name' => $districtRows->first()->district_name,
                    'total' => (int) $districtRows->sum('total'),
                    'computers' => $categoryTotal(self::CARD_TYPES['computers']),
                    'laptops' => $categoryTotal(self::CARD_TYPES['laptops']),
                    'printers' => $categoryTotal(self::CARD_TYPES['printers']),
                    'phones' => $categoryTotal(self::CARD_TYPES['phones']),
                    'network' => $categoryTotal(self::CARD_TYPES['network']),
                    'status' => $districtId && $attentionDistricts->contains($districtId) ? 'ATTENTION' : 'OPTIMAL',
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    protected function buildAllocation(array $cards): \Illuminate\Support\Collection
    {
        $allocationTotal = collect(self::ALLOCATION_CARDS)->sum(fn ($key) => $cards[$key]['total']);

        return collect(self::ALLOCATION_CARDS)->map(fn ($key) => [
            'key' => $key,
            'label' => self::ALLOCATION_LABELS[$key],
            'total' => $cards[$key]['total'],
            'percentage' => $allocationTotal > 0 ? round(($cards[$key]['total'] / $allocationTotal) * 100, 1) : 0.0,
            'color' => self::ALLOCATION_COLORS[$key],
        ])->values();
    }
}
