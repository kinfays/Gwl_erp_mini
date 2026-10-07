<?php

namespace App\Services\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Models\CommercialBillingBand;
use App\Models\CommercialBillingRoute;
use App\Models\CommercialImportBatch;
use App\Models\Region;
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
    ) {
    }

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
            $trend = $this->reading->monthlyTrend($stats, $this->filteredStrengths()->get(), $asOf);
            $unmatched = $stats->where('match_status', 'unmatched')->pluck('reader_staff_id')->unique()->count();
        }

        $batches = $this->scopeBatchesForActor(CommercialImportBatch::query())->notVoided()->with('region')->get();

        $summary = $this->insights->executiveSummary([
            'overdue' => app(UploadReminderService::class)->overdue($batches, $asOf),
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
        $byBatch = $family === [] ? collect() : CommercialBillingRoute::query()->whereIn('batch_id', array_column($family, 'id'))->get()->groupBy('batch_id');

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
        if ($districtId !== null && $this->hideSmallGroups()) {
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
     * Everything on the Billing screen for one snapshot (optionally one district).
     *
     * @return array<string, mixed>|null
     */
    public function billingSnapshot(?string $snapshotId, string $district = ''): ?array
    {
        $snapshot = $this->snapshot($snapshotId);

        if (! $snapshot) {
            return null;
        }

        $all = $this->routesOf($snapshot['id']);
        $routes = $district !== '' && $all->contains('district_label_raw', $district) ? $all->where('district_label_raw', $district)->values() : $all;

        return [
            'snapshot' => $snapshot,
            'district' => $routes === $all ? '' : $district,
            'overview' => $this->billing->overview($routes),
            'roll' => $this->billing->rollForward($routes),
            'collections' => $this->billing->collections($routes),
            'balances' => $this->billing->balances($routes),
            'estimation' => $this->billing->estimationAndUnbilled($routes),
            'bands' => $this->billing->bands(CommercialBillingBand::query()->where('batch_id', $snapshot['id'])->get()),
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
        $rows = $this->scopeReadingStatsForActor(\App\Models\CommercialReadingStat::query()->readers())
            ->whereHas('batch', fn ($batch) => $batch->notVoided()
                ->when($regionId !== null && $this->actorSeesAllRegions(), fn ($query) => $query->where('region_id', $regionId)))
            ->when(self::monthBound((string) $from, false), fn ($query, Carbon $date) => $query->whereDate('month', '>=', $date->toDateString()))
            ->when(self::monthBound((string) $to, true), fn ($query, Carbon $date) => $query->whereDate('month', '<=', $date->toDateString()))
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
            $previousVisits = $this->scopeReadingStatsForActor(\App\Models\CommercialReadingStat::query()->effective()->readers())
                ->whereDate('month', $previousMonth->toDateString())
                ->when($regionId !== null && $this->actorSeesAllRegions(), fn ($query) => $query->whereHas('batch', fn ($batch) => $batch->where('region_id', $regionId)))
                ->sum('visited_count');

            $cards[] = $this->insights->monthToDatePace($group, $batches, $month, $previousVisits > 0 ? (int) $previousVisits : null, $previousMonth->format('M Y'));
        }

        return $cards;
    }

    /** The routes of one billing snapshot, as every analysis reads them. @return Collection<int, CommercialBillingRoute> */
    public function snapshotRoutes(int $batchId): Collection
    {
        return $this->routesOf($batchId);
    }

    /** @return Collection<int, CommercialBillingRoute> */
    protected function routesOf(int $batchId): Collection
    {
        return CommercialBillingRoute::query()->where('batch_id', $batchId)->with('district:id,district_name')->get();
    }
}
