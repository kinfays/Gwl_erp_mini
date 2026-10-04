<?php

namespace App\Services\Assets;

use App\Models\IctAsset;
use App\Models\IctAssetIssueReport;
use App\Models\IctAssetMaintenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregations behind the Assets Summary page that AssetDashboardService does not already provide. Every method takes
 * a query the caller has already region-scoped and filtered (district, date range), so the scoping rules live in one
 * place (the Livewire component) and are applied identically on every tab.
 */
class AssetSummaryService
{
    /** Issue statuses that still need work; everything else (Resolved, Closed) is done. */
    public const UNRESOLVED_ISSUE_STATUSES = ['Open', 'In Progress'];

    /** Maintenance statuses that count as finished work for turnaround purposes. */
    public const COMPLETED_MAINTENANCE_STATUS = 'Completed';

    /** Device categories charted separately: computers/printers/etc. are not mixed with phones. */
    public const MODEL_GROUPS = [
        IctAsset::DEVICE_CATEGORY_ASSET => 'Assets',
        IctAsset::DEVICE_CATEGORY_PHONE => 'Phones',
        IctAsset::DEVICE_CATEGORY_NETWORK => 'Network devices',
    ];

    public function __construct(
        protected AssetDashboardService $dashboard,
        protected AssetReplacementService $replacement,
    ) {}

    /** A hand-edited URL with a bad date is ignored, not an error. */
    public static function parseDate(?string $value): ?string
    {
        try {
            return filled($value) ? CarbonImmutable::parse($value)->toDateString() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param Builder $scoped region-scoped IctAsset query */
    public function assetsIn(Builder $scoped, ?int $districtId): Builder
    {
        return $scoped->when($districtId, fn (Builder $q, int $id) => $q->where('ict_assets.district_id', $id));
    }

    /** Maintenance tickets of the (already filtered) assets, optionally limited to tickets opened in a date range. */
    public function maintenanceQuery(Builder $assets, ?string $from, ?string $to): Builder
    {
        return $this->inRange(
            IctAssetMaintenance::query()->whereIn('ict_asset_id', (clone $assets)->select('ict_assets.id')),
            'created_at',
            $from,
            $to
        );
    }

    /** @param Builder $scopedReports region-scoped IctAssetIssueReport query */
    public function issuesQuery(Builder $scopedReports, ?int $districtId, ?string $from, ?string $to): Builder
    {
        return $this->inRange(
            $scopedReports->when($districtId, fn (Builder $q, int $id) => $q->where('reporting_district_id', $id)),
            'created_at',
            $from,
            $to
        );
    }

    protected function inRange(Builder $query, string $column, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate($column, '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate($column, '<=', $to));
    }

    /**
     * The manufacturer/model breakdown once per device group, with doughnut slices (top manufacturers, the rest
     * folded into "Other").
     *
     * @return array<string, array{title: string, result: array, slices: array{labels: array, data: array, colors: array}}>
     */
    public function manufacturerGroups(Builder $assets, int $maxSlices = 7): array
    {
        $groups = [];

        foreach (self::MODEL_GROUPS as $category => $title) {
            $result = $this->manufacturersAndModels((clone $assets)->where('ict_assets.device_category', $category));

            $top = $result['rows']->take($maxSlices);
            $rest = (int) $result['rows']->slice($maxSlices)->sum('total');

            $labels = $top->pluck('manufacturer')->all();
            $data = $top->pluck('total')->all();
            $colors = array_map(fn (int $i) => 'series-'.($i + 1), array_keys($labels));

            if ($rest > 0) {
                $labels[] = 'Other';
                $data[] = $rest;
                $colors[] = 'muted';
            }

            $groups[$category] = ['title' => $title, 'result' => $result, 'slices' => compact('labels', 'data', 'colors')];
        }

        return $groups;
    }

    /**
     * Everything the Summary page shows, in one array, for the Excel and PDF exports.
     *
     * @param Builder $scopedAssets   region-scoped IctAsset query
     * @param Builder $scopedReports  region-scoped IctAssetIssueReport query
     */
    public function report(Builder $scopedAssets, Builder $scopedReports, ?int $districtId, ?string $from, ?string $to): array
    {
        $assets = $this->assetsIn($scopedAssets, $districtId);

        return [
            'needsAttention' => $this->needsAttention($assets),
            'age' => $this->dashboard->ageBuckets($assets),
            'warranty' => $this->dashboard->warrantyBuckets($assets),
            'replacement' => $this->replacement->buckets($assets),
            'unassigned' => $this->dashboard->unassigned($assets),
            'topAssignees' => $this->dashboard->topAssignees($assets),
            'manufacturers' => $this->manufacturerGroups($assets),
            'maintenance' => $this->maintenance($this->maintenanceQuery($assets, $from, $to), $from, $to),
            'issues' => $this->issues($this->issuesQuery($scopedReports, $districtId, $from, $to), $from, $to),
        ];
    }

    /**
     * Devices with a model recorded, grouped manufacturer -> model, plus how much of each category has no model at
     * all (the Assets form requires a model; Phones and Network leave it optional).
     *
     * @param Builder $assets region-scoped IctAsset query
     * @return array{total: int, with_model: int, rows: Collection, coverage: array<string, array{total: int, with_model: int}>}
     */
    public function manufacturersAndModels(Builder $assets): array
    {
        $coverage = [];
        foreach (IctAsset::DEVICE_CATEGORIES as $category) {
            $coverage[$category] = ['total' => 0, 'with_model' => 0];
        }

        (clone $assets)
            ->select('device_category', DB::raw('COUNT(*) as total'), DB::raw('COUNT(ict_asset_model_id) as with_model'))
            ->groupBy('device_category')
            ->get()
            ->each(function ($row) use (&$coverage) {
                $coverage[$row->device_category] = ['total' => (int) $row->total, 'with_model' => (int) $row->with_model];
            });

        $grouped = (clone $assets)
            ->join('ict_asset_models', 'ict_asset_models.id', '=', 'ict_assets.ict_asset_model_id')
            ->leftJoin('ict_asset_manufacturers', 'ict_asset_manufacturers.id', '=', 'ict_asset_models.ict_asset_manufacturer_id')
            ->select(
                'ict_asset_manufacturers.id as manufacturer_id',
                DB::raw("COALESCE(ict_asset_manufacturers.name, 'No manufacturer') as manufacturer_name"),
                'ict_asset_models.id as model_id',
                'ict_asset_models.name as model_name',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('ict_asset_manufacturers.id', 'ict_asset_manufacturers.name', 'ict_asset_models.id', 'ict_asset_models.name')
            ->get();

        $withModel = (int) $grouped->sum('total');
        $percent = fn (int $count) => $withModel > 0 ? round($count / $withModel * 100, 1) : 0.0;

        $rows = $grouped
            ->groupBy(fn ($row) => $row->manufacturer_id ?? 0)
            ->map(function (Collection $models) use ($percent) {
                $total = (int) $models->sum('total');

                return [
                    'manufacturer' => $models->first()->manufacturer_name,
                    'total' => $total,
                    'percentage' => $percent($total),
                    'models' => $models->sortByDesc('total')->values()->map(fn ($m) => [
                        'model' => $m->model_name,
                        'total' => (int) $m->total,
                        'percentage' => $percent((int) $m->total),
                    ])->all(),
                ];
            })
            ->sortByDesc('total')
            ->values();

        return [
            'total' => array_sum(array_column($coverage, 'total')),
            'with_model' => $withModel,
            'rows' => $rows,
            'coverage' => $coverage,
        ];
    }

    /**
     * @param Builder $maintenance region-scoped, filtered IctAssetMaintenance query
     * @return array{total: int, by_status: Collection, top_assets: Collection, avg_turnaround_days: ?float, completed_counted: int, monthly: array{labels: array, data: array}}
     */
    public function maintenance(Builder $maintenance, ?string $from = null, ?string $to = null): array
    {
        $byStatus = (clone $maintenance)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->orderByDesc('total')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        // "Most repaired" = tickets per asset within the filtered set.
        $top = (clone $maintenance)
            ->select('ict_asset_id', DB::raw('COUNT(*) as total'))
            ->groupBy('ict_asset_id')
            ->orderByDesc('total')
            ->orderBy('ict_asset_id')
            ->limit(10)
            ->get();
        $assets = IctAsset::query()->whereIn('id', $top->pluck('ict_asset_id'))->get(['id', 'asset_name', 'serial_number', 'device_category'])->keyBy('id');

        // Turnaround = days from the ticket being opened to its completion date; tickets without one are skipped.
        $turnarounds = (clone $maintenance)
            ->where('status', self::COMPLETED_MAINTENANCE_STATUS)
            ->whereNotNull('completion_date')
            ->get(['created_at', 'completion_date'])
            ->map(fn ($row) => max(0, $row->created_at->startOfDay()->diffInDays($row->completion_date->startOfDay(), false)));

        $dates = (clone $maintenance)->pluck('created_at');

        return [
            'total' => (int) $byStatus->sum(),
            'by_status' => $byStatus,
            'top_assets' => $top->map(fn ($row) => [
                'asset_id' => (int) $row->ict_asset_id,
                'asset' => $assets[$row->ict_asset_id] ?? null,
                'total' => (int) $row->total,
            ])->filter(fn ($row) => $row['asset'])->values(),
            'avg_turnaround_days' => $turnarounds->isEmpty() ? null : round((float) $turnarounds->avg(), 1),
            'completed_counted' => $turnarounds->count(),
            'monthly' => $this->monthly($dates, $from, $to),
        ];
    }

    /**
     * @param Builder $issues region-scoped, filtered IctAssetIssueReport query
     * @return array{total: int, by_type: Collection, by_status: Collection, monthly: array{labels: array, data: array}}
     */
    public function issues(Builder $issues, ?string $from = null, ?string $to = null): array
    {
        $byType = (clone $issues)->select('issue_type', DB::raw('COUNT(*) as total'))->groupBy('issue_type')->pluck('total', 'issue_type')
            ->map(fn ($total) => (int) $total);

        // Known types first in their canonical order, then anything else that has turned up.
        $ordered = collect(IctAssetIssueReport::ISSUE_TYPES)->mapWithKeys(fn ($type) => [$type => (int) ($byType[$type] ?? 0)])
            ->union($byType);

        $byStatus = (clone $issues)->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->orderByDesc('total')->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        return [
            'total' => (int) $byStatus->sum(),
            'by_type' => $ordered,
            'by_status' => $byStatus,
            'monthly' => $this->monthly((clone $issues)->pluck('created_at'), $from, $to),
        ];
    }

    /**
     * Assets that need someone's eye: condition Poor, status Damaged, or an unresolved issue report linked to them.
     * Newest-touched first; each row says why it is listed.
     *
     * @param Builder $assets region-scoped IctAsset query
     * @return Collection<int, array{asset: IctAsset, reasons: array<int, string>}>
     */
    public function needsAttention(Builder $assets, int $limit = 10): Collection
    {
        $unresolved = fn ($q) => $q->whereIn('status', self::UNRESOLVED_ISSUE_STATUSES);

        return (clone $assets)
            ->where(function (Builder $q) use ($unresolved) {
                $q->where('condition', IctAsset::CONDITION_POOR)
                    ->orWhere('status', IctAsset::STATUS_DAMAGED)
                    ->orWhereHas('issueReports', $unresolved);
            })
            ->withCount(['issueReports as open_issues_count' => $unresolved])
            ->with('district')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (IctAsset $asset) {
                $reasons = [];
                if ($asset->status === IctAsset::STATUS_DAMAGED) {
                    $reasons[] = 'Damaged';
                }
                if ($asset->condition === IctAsset::CONDITION_POOR) {
                    $reasons[] = 'Poor condition';
                }
                if ($asset->open_issues_count > 0) {
                    $reasons[] = $asset->open_issues_count.' open '.($asset->open_issues_count === 1 ? 'issue' : 'issues');
                }

                return ['asset' => $asset, 'reasons' => $reasons];
            })
            ->values();
    }

    /**
     * Count of timestamps per calendar month, zero-filled between the requested range (or, with none, the first and
     * last month that has data).
     *
     * @param Collection<int, \Carbon\CarbonInterface> $timestamps
     * @return array{labels: array<int, string>, data: array<int, int>}
     */
    public function monthly(Collection $timestamps, ?string $from = null, ?string $to = null): array
    {
        $counts = $timestamps->filter()->countBy(fn ($at) => $at->format('Y-m'));

        $start = $from ? CarbonImmutable::parse($from)->startOfMonth() : ($counts->isNotEmpty() ? CarbonImmutable::parse($counts->keys()->min().'-01') : null);
        $end = $to ? CarbonImmutable::parse($to)->startOfMonth() : ($counts->isNotEmpty() ? CarbonImmutable::parse($counts->keys()->max().'-01') : null);

        if (! $start || ! $end || $start->gt($end)) {
            return ['labels' => [], 'data' => []];
        }

        $labels = [];
        $data = [];

        for ($month = $start; $month->lte($end) && count($labels) < 60; $month = $month->addMonth()) {
            $labels[] = $month->format('M Y');
            $data[] = (int) ($counts[$month->format('Y-m')] ?? 0);
        }

        return ['labels' => $labels, 'data' => $data];
    }
}
