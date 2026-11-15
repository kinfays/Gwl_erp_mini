<?php

namespace App\Services\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Models\CommercialBillingBand;
use App\Models\CommercialBillingRoute;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingStat;
use App\Models\Region;
use App\Services\Commercial\BillingDetails\BillingDetailsReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Gathers, for the signed-in user, the scoped rows behind the Summary screen and every export and runs them through
 * the analytics services. The Summary page and the export controller both call this, so a file can never disagree with
 * the page it was downloaded from. It uses the same scoping trait as the Livewire screens (region scope, permissions).
 */
class CommercialReportData
{
    use ScopesCommercialByActor;

    public function __construct(
        protected ReadingAnalyticsService $reading,
        protected BillingAnalyticsService $billing,
        protected CommercialInsightsService $insights,
        protected CommercialKpiService $kpi,
    ) {}

    public function can(string $slug): bool
    {
        return $this->actorCan($slug);
    }

    public function canSeeReadingNumbers(): bool
    {
        return $this->actorCan('commercial.view_reading') || $this->actorCan('commercial.view_dashboard');
    }

    public function canSeeBillingNumbers(): bool
    {
        return $this->actorCan('commercial.view_billing') || $this->actorCan('commercial.view_dashboard');
    }

    /** C1 and C2 set billing beside reading, so they need both permissions in their own right. */
    public function canCombine(): bool
    {
        return $this->actorCan('commercial.view_billing') && $this->actorCan('commercial.view_reading');
    }

    public function seesAllRegions(): bool
    {
        return $this->actorSeesAllRegions();
    }

    public function regionLabelFor(?array $snapshot = null): string
    {
        if ($snapshot) {
            return $snapshot['region'];
        }

        return $this->actorSeesAllRegions() ? 'All regions' : (Region::query()->whereKey($this->actorRegionId())->value('region_name') ?? 'Own region');
    }

    // ---------------------------------------------------------------- snapshots

    /** @return list<array<string, mixed>> */
    public function snapshots(): array
    {
        return $this->billing->snapshots($this->scopeBatchesForActor(CommercialImportBatch::query()));
    }

    /** @return array<string, mixed>|null */
    public function snapshot(?string $requestedId = null): ?array
    {
        $snapshots = $this->snapshots();

        return $this->chooseSnapshot($snapshots, $requestedId, $this->billing->defaultSnapshot($snapshots));
    }

    // ---------------------------------------------------------------- Summary (C3, C1, C2)

    /**
     * Every section is computed only for the permissions the user holds.
     *
     * @return array<string, mixed>
     */
    public function summary(?string $snapshotId = null): array
    {
        $asOf = now();
        $canReading = $this->canSeeReadingNumbers();
        $canBilling = $this->canSeeBillingNumbers();

        $snapshots = $canBilling || $this->actorCan('commercial.view_dashboard') ? $this->snapshots() : [];
        $snapshot = $canBilling ? $this->chooseSnapshot($snapshots, $snapshotId, $this->billing->defaultSnapshot($snapshots)) : null;
        $routes = $snapshot ? $this->routesOf($snapshot['id']) : null;

        $trend = null;
        $unmatched = null;

        if ($canReading) {
            $stats = $this->filteredReadingStats()->get();

            // a district user whose district has too few CCAs gets no reading figure that would be those people's own
            if ($this->districtGroupTooSmall($stats)) {
                $stats = collect();
            }

            $trend = $this->reading->monthlyTrend($stats, $this->filteredStrengths()->get(), $asOf);
            $unmatched = $stats->where('match_status', 'unmatched')->pluck('reader_staff_id')->unique()->count();
        }

        $batches = $this->scopeBatchesForActor(CommercialImportBatch::query())->notVoided()->with('region')->get();

        $summary = $this->insights->executiveSummary([
            // (the overdue list is a region-wide status: a district user does not have it)
            'overdue' => $this->actorDistrictLimited() ? [] : app(UploadReminderService::class)->overdue($batches, $asOf),
            'trend' => $trend,
            'unmatched_readers' => $unmatched,
            'snapshot' => $snapshot,
            'routes' => $routes,
            'snapshots' => $canBilling ? $snapshots : null,
            'batches' => $batches,
        ]);

        $combined = $this->canCombine() && $snapshot ? $this->combined($snapshot, $routes, $snapshots, $asOf) : null;

        return [
            'summary' => $summary,
            'scorecard' => $combined['scorecard'] ?? null,
            'estimation' => $combined['estimation'] ?? null,
            'snapshot' => $snapshot,
            'snapshots' => $snapshots,
            'region_label' => $this->regionLabelFor($snapshot),
            'can_reading' => $canReading,
            'can_billing' => $canBilling,
            'can_combine' => $this->canCombine(),
        ];
    }

    /**
     * C1 and C2 for one billing snapshot. Reading rows are those of the snapshot's own region.
     *
     * @return array{scorecard: array<string, mixed>, estimation: array<string, mixed>}
     */
    public function combined(array $snapshot, Collection $routes, array $snapshots, Carbon $asOf): array
    {
        $stats = $this->filteredReadingStats($snapshot['region_id'])->with(['district:id,district_name'])->get();

        $family = array_values(array_filter($snapshots, fn (array $s) => $s['is_single_month'] && $s['region_id'] === $snapshot['region_id'] && $s['segment'] === $snapshot['segment']));
        $byBatch = $family === [] ? collect() : $this->scopeBillingRoutesForActor(CommercialBillingRoute::query()->whereIn('batch_id', array_column($family, 'id')))->get()->groupBy('batch_id');

        return [
            'scorecard' => $this->insights->districtScorecard($snapshot, $routes, $stats, $asOf, $this->hideSmallGroups(), $this->minReadersForDistricts()),
            'estimation' => $this->insights->estimationVsSkip(
                array_map(fn (array $meta) => ['meta' => $meta, 'routes' => $byBatch[$meta['id']] ?? collect()], $family),
                $stats,
                $asOf
            ),
        ];
    }

    // ---------------------------------------------------------------- exports

    /**
     * R1-R5 for the Reading screen's filters.
     *
     * @return array<string, mixed>
     */
    public function readingTrend(?int $regionId, ?int $districtId, ?string $from, ?string $to): array
    {
        $min = $this->minReadersForDistricts();
        $stats = $this->filteredReadingStats($regionId, $districtId, $from, $to)->get();

        // A district filter shows ONE home district's figures: for a user who may not see individual readers, a district
        // with too few active readers would be those people's own figures.
        if (($districtId !== null || $this->actorDistrictLimited()) && $this->hideSmallGroups()) {
            $active = $stats->where('visited_count', '>', 0)->pluck('reader_staff_id')->unique()->count();

            if ($active < $min) {
                return ['trend' => [], 'growth' => [], 'coverage_hidden' => true, 'small_group' => true, 'min_readers' => $min, 'active_readers' => $active];
            }
        }

        $strengths = $this->filteredStrengths($regionId, $from, $to)->get();

        return [
            'trend' => $this->reading->monthlyTrend($stats, $strengths, now(), withCoverage: $districtId === null),
            'growth' => $this->reading->strengthGrowth($strengths),
            'coverage_hidden' => $districtId !== null,
            'small_group' => false,
            'min_readers' => $min,
        ];
    }

    public function hidesSmallGroups(): bool
    {
        return $this->hideSmallGroups();
    }

    public function minReadersForDistricts(): int
    {
        return max(1, (int) config('gwl.commercial_min_readers_for_district_figures', 3));
    }

    /**
     * R6-R13 for the Reading screen's filters.
     *
     * @return array<string, mixed>
     */
    public function readers(?int $regionId, ?int $districtId, ?string $from, ?string $to): array
    {
        $stats = $this->filteredReadingStats($regionId, $districtId, $from, $to)->with(['employee:id,full_name', 'district:id,district_name'])->get();
        $asOf = now();
        $table = $this->reading->readerTable($stats);

        return [
            'readers' => $table,
            'quality' => $this->reading->quality($table),
            'consistency' => $this->reading->consistency($stats, $asOf),
            'movement' => $this->reading->movement($stats, $asOf),
            'inactive' => $this->reading->inactive($stats, $asOf),
            'workload' => $this->reading->workload($stats, $asOf),
            'outliers' => $this->reading->outliers($stats, $asOf),
            'scorecard' => $this->reading->scorecard($stats, $asOf),
        ];
    }

    /**
     * The reading report BY ROUTE AND CCA (Phase 8c, design 8.36): the uploads the typed range uses (the five period rules), the analyses
     * R1 to R8, the trend by upload and the small-group rule, all for the viewer's own scope. The Reading screen and the export both call it.
     *
     * @return array<string, mixed>
     */
    public function routeReading(?int $regionId = null, ?int $districtId = null, ?string $from = null, ?string $to = null): array
    {
        return app(ReadingRouteReport::class)->build($regionId, $districtId, $from, $to);
    }

    /**
     * The Detailed Billing Report analyses B1 to B13 (Phase 8d, design 8.39): the uploads the typed range uses (the five period rules), the rollups
     * collapsed over them, the capped account lists, the trend by upload and the CCA of each route, all for the viewer's own scope. The Billing
     * details screen and the export both call it.
     *
     * @return array<string, mixed>
     */
    public function billingDetails(?int $regionId = null, ?int $districtId = null, ?string $category = null, ?string $cca = null, ?int $routeId = null, ?string $from = null, ?string $to = null): array
    {
        return app(BillingDetailsReport::class)->build($regionId, $districtId, $category, $cca, $routeId, $from, $to);
    }

    /**
     * One analysis that needs more than one file (X1, X3 to X12; Phase 8e, design 8.42), for the viewer's own scope and permissions. The page and the
     * export both call it.
     *
     * @param  array{region?: int|null, district?: int|null, from?: string|null, to?: string|null, mode?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function cross(string $key, array $filters): array
    {
        return app(Cross\CrossFileReport::class)->build($key, $filters);
    }

    /**
     * Route 360 (X2): one route on one page. Null when the route does not exist or is outside the viewer's reach.
     *
     * @param  array{from?: string|null, to?: string|null, mode?: string|null}  $filters
     * @return array<string, mixed>|null
     */
    public function crossRoute(int $routeId, array $filters = []): ?array
    {
        return app(Cross\CrossFileReport::class)->route($routeId, $filters);
    }

    /**
     * A DATE RANGE of billing (Phase 8a, CommercialPeriods): the uploads of the snapshot's region and segment that lie inside it, chosen
     * by the period rules, with their routes added up (flows summed, stocks from the latest period). Null when no range is typed.
     *
     * @param  array<string, mixed>  $snapshot  the snapshot that names the region and segment (the default or the picked one)
     * @return array{meta: array<string, mixed>, routes: Collection<int, CommercialBillingRoute>, notes: list<string>, batch_ids: list<int>, selection: array<string, mixed>}|null
     */
    public function billingRange(array $snapshot, ?string $from, ?string $to): ?array
    {
        $start = CommercialPeriods::bound($from, false);
        $end = CommercialPeriods::bound($to, true);

        if ($start === null && $end === null) {
            return null;
        }

        $periods = new CommercialPeriods;
        $batches = $this->scopeBatchesForActor(CommercialImportBatch::query())->ofType(CommercialImportBatch::TYPE_BILLING_SUMMARY)->notVoided()
            ->where('region_id', $snapshot['region_id'])
            ->when($snapshot['segment'] === null, fn ($q) => $q->whereNull('customer_segment'), fn ($q) => $q->where('customer_segment', $snapshot['segment']))
            ->get()->keyBy('id');

        $selection = $periods->select($batches->map(fn (CommercialImportBatch $b) => ['id' => $b->id, 'from' => $b->period_from->toDateString(), 'to' => $b->period_to->toDateString(), 'order' => $b->id])->values()->all(), $start?->toDateString(), $end?->toDateString());
        $chosen = array_map('intval', $selection['chosen']);
        $routesByBatch = $chosen === [] ? collect() : $this->scopeBillingRoutesForActor(CommercialBillingRoute::query()->whereIn('batch_id', $chosen))->with('district:id,district_name')->get()->groupBy('batch_id');
        $routes = $chosen === [] ? collect() : $periods->combineBillingRoutes($routesByBatch->all(), $chosen);

        $label = CommercialPeriods::describe($selection['from'] ?? now()->toDateString(), $selection['to'] ?? now()->toDateString());
        $notes = [];

        if (count($chosen) > 1) {
            $notes[] = count($chosen).' uploads that do not overlap are added up (batch '.implode(', ', array_map(fn ($id) => '#'.$id, $chosen)).'): flows (billing, payments, volume) are summed, and the closing balance and customer counts are the latest upload\'s.';
        } elseif (count($chosen) === 1) {
            $notes[] = 'One upload lies inside this range (batch #'.$chosen[0].').';
        } else {
            $notes[] = 'No upload lies inside this range.';
        }

        if ($selection['gaps'] !== [] && $chosen !== []) {
            $notes[] = 'No upload covers '.CommercialPeriods::describeIntervals($selection['gaps']).' of the range ('.$selection['covered_days'].' of '.$selection['range_days'].' days are covered).';
        }

        foreach ($selection['overlaps'] as $overlap) {
            $notes[] = 'Batch #'.$overlap['dropped'].' overlaps batch #'.$overlap['kept'].' and is left out of this view (both are kept): the newest upload is used first.';
        }

        foreach ($selection['shadowed'] as $shadow) {
            if ($batches->has($shadow['id']) && $this->periodsInRange($batches[$shadow['id']], $selection)) {
                $notes[] = 'Batch #'.$shadow['id'].' is replaced by the newer batch #'.$shadow['by'].' ('.($shadow['reason'] === 'identical' ? 'the same period' : 'a period that contains it').').';
            }
        }

        if ($selection['partly_outside'] !== []) {
            $notes[] = 'Left out because their period is only partly inside the range: '.implode(', ', array_map(fn ($id) => '#'.$id.' ('.CommercialPeriods::describe($batches[$id]->period_from, $batches[$id]->period_to).')', $selection['partly_outside'])).'.';
        }

        $meta = [
            'id' => 'range', 'batch' => null, 'is_range' => true, 'range_from' => $selection['from'], 'range_to' => $selection['to'], 'batch_ids' => $chosen,
            'region_id' => $snapshot['region_id'], 'region' => $snapshot['region'], 'segment' => $snapshot['segment'], 'segment_label' => $snapshot['segment_label'],
            'period_from' => $selection['from'], 'period_to' => $selection['to'], 'month' => $selection['from'] ? substr($selection['from'], 0, 7).'-01' : null,
            'period_label' => $label, 'is_single_month' => false, 'in_one_month' => false, 'is_part_month' => false,
            'label' => $snapshot['region'].' · '.$snapshot['segment_label'].' · '.$label.(count($chosen) > 1 ? ' ('.count($chosen).' uploads added up)' : ''),
        ];

        return ['meta' => $meta, 'routes' => $routes, 'notes' => $notes, 'batch_ids' => $chosen, 'selection' => $selection];
    }

    /** True when a batch's period overlaps the selected range (so a note about it is relevant). */
    protected function periodsInRange(CommercialImportBatch $batch, array $selection): bool
    {
        return $selection['from'] !== null && $batch->period_to->toDateString() >= $selection['from'] && $batch->period_from->toDateString() <= $selection['to'];
    }

    /**
     * Everything on the Billing screen for one snapshot (optionally one district), or for a date range when $from / $to are given.
     *
     * @return array<string, mixed>|null
     */
    public function billingSnapshot(?string $snapshotId, string $district = '', ?string $from = null, ?string $to = null): ?array
    {
        $snapshot = $this->snapshot($snapshotId);

        if (! $snapshot) {
            return null;
        }

        $range = $this->billingRange($snapshot, $from, $to);

        if ($range !== null) {
            $snapshot = $range['meta'];
            $all = $range['routes'];
            $bandsBatch = $range['batch_ids'] === [] ? null : max($range['batch_ids']);
        } else {
            $all = $this->routesOf($snapshot['id']);
            $bandsBatch = $snapshot['id'];
        }
        $routes = $district !== '' && $all->contains('district_label_raw', $district) ? $all->where('district_label_raw', $district)->values() : $all;

        return [
            'snapshot' => $snapshot,
            'district' => $routes === $all ? '' : $district,
            'overview' => $this->billing->overview($routes),
            'roll' => $this->billing->rollForward($routes),
            'collections' => $this->billing->collections($routes),
            'balances' => $this->billing->balances($routes),
            'estimation' => $this->billing->estimationAndUnbilled($routes),
            'bands' => $this->billing->bands($this->actorDistrictLimited() || $bandsBatch === null ? collect() : CommercialBillingBand::query()->where('batch_id', $bandsBatch)->get()),
            'range_notes' => $range['notes'] ?? [],
            'exceptions' => $this->billing->exceptions($routes),
        ];
    }

    /** @return array<string, mixed>|null */
    public function scorecard(?string $snapshotId): ?array
    {
        $snapshot = $this->snapshot($snapshotId);

        if (! $snapshot) {
            return null;
        }

        $combined = $this->combined($snapshot, $this->routesOf($snapshot['id']), $this->snapshots(), now());

        return ['snapshot' => $snapshot, 'scorecard' => $combined['scorecard']];
    }

    // ---------------------------------------------------------------- R14

    /**
     * Pace cards for the months that have two or more uploads (voided uploads left out; replaced ones kept: their rows
     * are retained). Newest month first.
     *
     * @return list<array<string, mixed>>
     */
    public function paceCards(?int $regionId = null, ?string $from = null, ?string $to = null, int $limit = 3): array
    {
        $rows = $this->scopeReadingStatsForActor(CommercialReadingStat::query()->readers())
            ->whereHas('batch', fn ($batch) => $batch->notVoided()
                ->when($regionId !== null && $this->actorSeesAllRegions(), fn ($query) => $query->where('region_id', $regionId)))
            ->when(is_array(self::monthRange($from, $to)), fn ($query) => $query->whereDate('month', '>=', self::monthRange($from, $to)[0]->toDateString())->whereDate('month', '<=', self::monthRange($from, $to)[1]->toDateString()))
            ->when(self::monthRange($from, $to) === false, fn ($query) => $query->whereRaw('1 = 0'))
            ->selectRaw('batch_id, month, sum(read_count) as read_total, sum(skipped_count) as skipped_total, sum(visited_count) as visited_total')
            ->groupBy('batch_id', 'month')
            ->get()
            ->groupBy(fn ($row) => substr((string) $row->month, 0, 10));

        $months = $rows->filter(fn (Collection $group) => $group->pluck('batch_id')->unique()->count() >= 2)->sortKeysDesc()->take($limit);

        if ($months->isEmpty()) {
            return [];
        }

        $batches = CommercialImportBatch::query()->whereIn('id', $months->flatten(1)->pluck('batch_id')->unique())->get()->keyBy('id');
        $cards = [];

        foreach ($months as $month => $group) {
            $previousMonth = Carbon::parse($month)->subMonth();
            $previousVisits = $this->scopeReadingStatsForActor(CommercialReadingStat::query()->effective()->readers())
                ->whereDate('month', $previousMonth->toDateString())
                ->when($regionId !== null && $this->actorSeesAllRegions(), fn ($query) => $query->whereHas('batch', fn ($batch) => $batch->where('region_id', $regionId)))
                ->sum('visited_count');

            $cards[] = $this->insights->monthToDatePace($group, $batches, $month, $previousVisits > 0 ? (int) $previousVisits : null, $previousMonth->format('M Y'));
        }

        return $cards;
    }

    /**
     * The KPI scorecard (sales, billing, collection against the district targets, and the arrears table) for the viewer's own
     * scope. Returns what the screen and the export both need: the choices (regions, segments), what was chosen, and the tables.
     * A viewer who sees one region only ever gets that region; a requested region or segment that does not exist falls back to the
     * default instead of saying it exists elsewhere.
     *
     * @return array<string, mixed>
     */
    public function kpis(?int $regionId = null, ?string $segment = null, int $months = 3, ?int $districtId = null): array
    {
        // Phase 8b (step 0.1): every batch inside ONE calendar month is a candidate; whether a month is full or only a part of one is
        // decided by CommercialKpiService::months() from the batches' own days, never from "it is inside one month".
        $snapshots = array_values(array_filter($this->snapshots(), fn (array $s) => $s['in_one_month']));
        $regions = collect($snapshots)->unique('region_id')->mapWithKeys(fn (array $s) => [(int) $s['region_id'] => $s['region']])->all();

        // newest snapshot first: the default region is the one with the latest data
        $regionId = $regionId !== null && isset($regions[$regionId]) ? $regionId : (isset($snapshots[0]) ? (int) $snapshots[0]['region_id'] : null);
        $inRegion = array_values(array_filter($snapshots, fn (array $s) => (int) $s['region_id'] === $regionId));
        $segments = collect($inRegion)->groupBy('segment')->map(fn ($group) => ['label' => $group->first()['segment_label'], 'months' => $group->count()])->all();

        if ($segment === null || ! array_key_exists($segment, $segments)) {
            $segment = collect($segments)->sortByDesc('months')->keys()->first();
        }

        $picked = $this->kpi->months($snapshots, $regionId, $segment === '' ? null : $segment, max(1, min(12, $months)));

        $base = ['regions' => $regions, 'region_id' => $regionId, 'region' => $regions[$regionId] ?? null, 'segments' => $segments, 'segment' => $segment, 'months_asked' => $months, 'age_note' => CommercialKpiService::AGE_NOTE, 'arrears_note' => CommercialKpiService::ARREARS_NOTE];

        if ($picked === []) {
            return $base + ['empty' => true, 'months' => [], 'districts' => [], 'total' => null, 'district_options' => []];
        }

        // a district user's KPI table holds only their district: no 'All districts' row, no other district's figure
        $card = $this->kpi->scorecard($picked, $this->actorDistrictIds());
        $options = collect($card['districts'])->mapWithKeys(fn (array $d) => [($d['id'] ?? 'x'.crc32($d['name'])) => $d['name']])->all();

        if ($districtId !== null) {
            $card['districts'] = array_values(array_filter($card['districts'], fn (array $d) => $d['id'] === $districtId));
        }

        return $base + ['empty' => false, 'district_options' => $options, 'district_id' => $districtId] + $card;
    }

    /** The routes of one billing snapshot, as every analysis reads them. @return Collection<int, CommercialBillingRoute> */
    public function snapshotRoutes(int $batchId): Collection
    {
        return $this->routesOf($batchId);
    }

    /** @return Collection<int, CommercialBillingRoute> */
    protected function routesOf(int $batchId): Collection
    {
        return $this->scopeBillingRoutesForActor(CommercialBillingRoute::query()->where('batch_id', $batchId))->with('district:id,district_name')->get();
    }
}
