<?php

namespace App\Services\Assets;

use App\Models\Employee;
use App\Models\IctAsset;
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

    /** Age buckets in whole years: [min inclusive, max exclusive|null, label]. 5+ is open-ended. */
    public const AGE_BUCKETS = [
        '0-1' => [0, 1, '0-1 years'],
        '1-2' => [1, 2, '1-2 years'],
        '2-3' => [2, 3, '2-3 years'],
        '3-4' => [3, 4, '3-4 years'],
        '4-5' => [4, 5, '4-5 years'],
        '5+' => [5, null, '5+ years'],
    ];

    /** Warranty buckets are mutually exclusive; "expiring_90" means 31-90 days out. */
    public const WARRANTY_BUCKETS = [
        'active' => 'Active',
        'expiring_30' => 'Expiring within 30 days',
        'expiring_90' => 'Expiring within 90 days',
        'expired' => 'Expired',
        'unknown' => 'Unknown',
    ];

    /** The list page that owns each device_category, so dashboard links land on the right screen. */
    public const CATEGORY_ROUTES = [
        IctAsset::DEVICE_CATEGORY_ASSET => 'assets.assets',
        IctAsset::DEVICE_CATEGORY_PHONE => 'assets.phones',
        IctAsset::DEVICE_CATEGORY_NETWORK => 'assets.network',
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
            'unassigned' => $this->unassigned($scopedAssets),
            'cards' => $cards,
            'districtBreakdown' => $districtBreakdown,
            'allocation' => $allocation,
        ];
    }

    /**
     * Narrow a query to an age range in whole years since purchased_at. $minYears is inclusive, $maxYears exclusive
     * (age 2-3 = at least 2 years old, not yet 3). Assets with no purchase date never match a range; ask for them
     * with $unknown. The dashboard and all three lists call this, so a bucket count and its drill-down cannot drift.
     */
    public function applyAgeRange(Builder $query, ?int $minYears, ?int $maxYears, bool $unknown = false): Builder
    {
        $column = $query->getModel()->qualifyColumn('purchased_at');

        if ($unknown) {
            return $query->whereNull($column);
        }

        $today = now()->startOfDay();

        $query->whereNotNull($column);

        if ($minYears !== null && $minYears > 0) {
            $query->whereDate($column, '<=', $today->copy()->subYears($minYears)->toDateString());
        }

        if ($maxYears !== null) {
            $query->whereDate($column, '>', $today->copy()->subYears($maxYears)->toDateString());
        }

        return $query;
    }

    /** @param string $bucket one of the WARRANTY_BUCKETS keys; anything else leaves the query untouched */
    public function applyWarranty(Builder $query, string $bucket): Builder
    {
        $column = $query->getModel()->qualifyColumn('warranty_expires_at');
        $today = now()->startOfDay();

        return match ($bucket) {
            'unknown' => $query->whereNull($column),
            'expired' => $query->whereDate($column, '<', $today->toDateString()),
            'expiring_30' => $query->whereDate($column, '>=', $today->toDateString())
                ->whereDate($column, '<=', $today->copy()->addDays(30)->toDateString()),
            'expiring_90' => $query->whereDate($column, '>', $today->copy()->addDays(30)->toDateString())
                ->whereDate($column, '<=', $today->copy()->addDays(90)->toDateString()),
            'active' => $query->whereDate($column, '>', $today->copy()->addDays(90)->toDateString()),
            default => $query,
        };
    }

    /** "In store" and "no current holder" both land here; see the Tier B Available status. */
    public function applyUnassigned(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->qualifyColumn('assigned_to_employee_id'));
    }

    /**
     * Every scoped asset in exactly one age bucket, plus Unknown for a missing purchase date.
     *
     * @return array<int, array{key: string, label: string, total: int, by_category: array<string, int>, params: array<string, mixed>}>
     */
    public function ageBuckets(Builder $scopedAssets): array
    {
        $rows = [];

        foreach (self::AGE_BUCKETS as $key => [$min, $max, $label]) {
            $rows[] = $this->bucketRow(
                $key,
                $label,
                $this->applyAgeRange(clone $scopedAssets, $min, $max),
                $max === null ? ['age_min' => $min] : ['age_min' => $min, 'age_max' => $max],
            );
        }

        $rows[] = $this->bucketRow('unknown', 'Unknown', $this->applyAgeRange(clone $scopedAssets, null, null, true), ['age' => 'unknown']);

        return $rows;
    }

    /** @return array<int, array{key: string, label: string, total: int, by_category: array<string, int>, params: array<string, mixed>}> */
    public function warrantyBuckets(Builder $scopedAssets): array
    {
        $rows = [];

        foreach (self::WARRANTY_BUCKETS as $key => $label) {
            $rows[] = $this->bucketRow($key, $label, $this->applyWarranty(clone $scopedAssets, $key), ['warranty' => $key]);
        }

        return $rows;
    }

    /** @return array{key: string, label: string, total: int, by_category: array<string, int>, params: array<string, mixed>} */
    public function unassigned(Builder $scopedAssets): array
    {
        return $this->bucketRow('unassigned', 'Unassigned', $this->applyUnassigned(clone $scopedAssets), ['assigned' => 'none']);
    }

    /**
     * Employees holding the most assets across all three categories. Ties break on employee id so the list is stable.
     *
     * @return \Illuminate\Support\Collection<int, array{employee_id: int, name: string, total: int}>
     */
    public function topAssignees(Builder $scopedAssets, int $limit = 10): \Illuminate\Support\Collection
    {
        $column = $scopedAssets->getModel()->qualifyColumn('assigned_to_employee_id');

        $counts = (clone $scopedAssets)
            ->whereNotNull($column)
            ->select($column.' as employee_id', DB::raw('COUNT(*) as total'))
            ->groupBy($column)
            ->orderByDesc('total')
            ->orderBy($column)
            ->limit($limit)
            ->get();

        $names = Employee::query()->whereIn('id', $counts->pluck('employee_id'))->pluck('full_name', 'id');

        return $counts->map(fn ($row) => [
            'employee_id' => (int) $row->employee_id,
            'name' => (string) ($names[$row->employee_id] ?? 'Unknown employee'),
            'total' => (int) $row->total,
        ])->values();
    }

    /** @return array{key: string, label: string, total: int, by_category: array<string, int>, params: array<string, mixed>} */
    public function bucketRow(string $key, string $label, Builder $query, array $params): array
    {
        $categoryColumn = $query->getModel()->qualifyColumn('device_category');

        $counts = $query
            ->select($categoryColumn.' as category', DB::raw('COUNT(*) as total'))
            ->groupBy($categoryColumn)
            ->pluck('total', 'category');

        $byCategory = [];
        foreach (IctAsset::DEVICE_CATEGORIES as $category) {
            $byCategory[$category] = (int) ($counts[$category] ?? 0);
        }

        return [
            'key' => $key,
            'label' => $label,
            'total' => array_sum($byCategory),
            'by_category' => $byCategory,
            'params' => $params,
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
