<?php

namespace App\Services\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingRouteStat;
use App\Models\CommercialRoute;
use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\Customers\CustomerAnalyticsService;
use App\Services\Commercial\Customers\CustomerSnapshots;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The reading report BY ROUTE AND CCA for the signed-in user (Phase 8c, design 8.36): which uploads the typed range uses (the five
 * CommercialPeriods rules, exactly as billing), the scoped rows of those uploads, the analyses R1 to R8 (ReadingRouteAnalyticsService),
 * the trend by upload and the small-group rule. The Reading screen and the exports both get their data from here (through
 * CommercialReportData::routeReading()), so a file can never disagree with the page it came from.
 *
 * Scope is the one scoping trait: a viewer reads only the uploads of the regions they may see, and a district user only their own
 * district's rows (a row with no district is not theirs). A viewer without commercial.view_reader_performance sees districts and counts
 * but not named CCAs, and a district with fewer than commercial_min_readers_for_district_figures active CCAs has its figures held back
 * (they would be those people's own).
 */
class ReadingRouteReport
{
    use ScopesCommercialByActor;

    /** The trend shows at most this many uploads (the newest). */
    public const TREND_LIMIT = 24;

    public function __construct(protected ReadingRouteAnalyticsService $analytics) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?int $regionId = null, ?int $districtId = null, ?string $from = null, ?string $to = null): array
    {
        $named = $this->actorCan('commercial.view_reader_performance');
        $base = [
            'allowed' => $this->actorCan('commercial.view_reading'), 'available' => false, 'named' => $named, 'regions' => [], 'region_id' => null, 'region' => null,
            'districts' => [], 'district_id' => null, 'month_options' => [], 'range' => null, 'notes' => [], 'analysis' => null, 'trend' => [], 'unread_routes' => null,
            'strength' => null, 'small_group' => false, 'held_back' => [], 'min_readers' => $this->minReaders(), 'district_limited' => $this->actorDistrictLimited(),
        ];

        if (! $base['allowed']) {
            return $base;
        }

        $batches = $this->scopeBatchesForActor(CommercialImportBatch::query())->ofType(CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY)->notVoided()->get();

        if ($batches->isEmpty()) {
            return $base;
        }

        $regionNames = Region::query()->whereIn('id', $batches->pluck('region_id')->filter()->unique()->all())->orderBy('region_name')->pluck('region_name', 'id')->all();
        $newest = $batches->sortByDesc('id')->first();
        $regionId = $regionId !== null && isset($regionNames[$regionId]) ? $regionId : (int) $newest->region_id;
        $inRegion = $batches->where('region_id', $regionId)->keyBy('id');

        $units = $inRegion->map(fn (CommercialImportBatch $b) => ['id' => $b->id, 'from' => $b->period_from->toDateString(), 'to' => $b->period_to->toDateString(), 'order' => $b->id])->values()->all();
        $start = CommercialPeriods::bound($from, false);
        $end = CommercialPeriods::bound($to, true);
        $periods = new CommercialPeriods;
        $selection = $periods->select($units, $start?->toDateString(), $end?->toDateString());
        $chosen = array_map('intval', $selection['chosen']);

        // the trend: the typed range, or (no range typed) every live upload of the region, newest 24
        $ranged = $start !== null || $end !== null;
        $trendSelection = $ranged ? $selection : $periods->select($units, min(array_column($units, 'from')), max(array_column($units, 'to')));
        $trendIds = array_slice(array_map('intval', $trendSelection['chosen']), -self::TREND_LIMIT);

        // the district filter is only honoured for a district that has rows the viewer may see
        $options = $this->districtOptions($chosen);

        if ($districtId !== null && ! isset($options[$districtId])) {
            $districtId = null;
        }

        $rows = $this->rows(array_values(array_unique(array_merge($chosen, $trendIds))), $districtId);
        $chosenRows = $rows->filter(fn (array $r) => in_array($r['b'], $chosen, true))->values();
        $unitById = collect($units)->keyBy('id');
        $periodOf = fn (array $ids) => collect($ids)->mapWithKeys(fn (int $id) => [$id => ['from' => $unitById[$id]['from'], 'to' => $unitById[$id]['to']]])->all();
        $districtNames = District::query()->whereIn('id', $rows->pluck('district_id')->filter()->unique()->all())->pluck('district_name', 'id')->all();

        $analysis = $this->analytics->analyse($chosenRows, $periodOf($chosen), $districtNames);
        $trend = $this->analytics->trend($rows->filter(fn (array $r) => in_array($r['b'], $trendIds, true))->values(), $periodOf($trendIds), $districtNames);
        $unread = $this->unreadRoutes($regionId, $districtId, $chosenRows, $districtNames);
        $strength = $this->strengthCheck($analysis, $regionId, $districtId);

        $label = CommercialPeriods::describe($selection['from'] ?? now()->toDateString(), $selection['to'] ?? now()->toDateString());
        $out = [
            'allowed' => true, 'available' => true, 'named' => $named, 'regions' => $regionNames, 'region_id' => $regionId, 'region' => $regionNames[$regionId] ?? null,
            'districts' => $options, 'district_id' => $districtId, 'district_limited' => $this->actorDistrictLimited(), 'month_options' => $this->monthOptions($units),
            'range' => [
                'from' => $selection['from'], 'to' => $selection['to'], 'label' => $label, 'typed' => $ranged, 'batch_ids' => $chosen,
                'covered_days' => $selection['covered_days'], 'range_days' => $selection['range_days'], 'gaps' => $selection['gaps'],
                'batches' => array_map(fn (int $id) => ['id' => $id, 'label' => CommercialPeriods::describe($unitById[$id]['from'], $unitById[$id]['to']), 'uploaded' => optional($inRegion[$id]->created_at)->toDateTimeString()], $chosen),
            ],
            'notes' => $this->notes($selection, $chosen, $inRegion),
            'analysis' => $analysis, 'trend' => $trend, 'unread_routes' => $unread, 'strength' => $strength, 'counts' => $this->counts($analysis),
            'small_group' => false, 'held_back' => [], 'min_readers' => $this->minReaders(),
        ];

        return $named ? $out : $this->smallGroupRule($out);
    }

    /**
     * The calendar months the region's uploads reach (newest first, at most 36): the "a whole month" shortcut of the range control.
     *
     * @param  list<array{id: int|string, from: string, to: string, order: int}>  $units
     * @return array<string, string> Y-m => 'Oct 2026'
     */
    protected function monthOptions(array $units): array
    {
        if ($units === []) {
            return [];
        }

        $first = Carbon::parse(min(array_column($units, 'from')))->startOfMonth();
        $out = [];

        for ($month = Carbon::parse(max(array_column($units, 'to')))->startOfMonth(); $month->gte($first) && count($out) < 36; $month = $month->copy()->subMonthNoOverflow()) {
            $out[$month->format('Y-m')] = $month->format('M Y');
        }

        return $out;
    }

    // ---------------------------------------------------------------- rows

    /**
     * @param  list<int>  $batchIds
     * @return Collection<int, array<string, mixed>> arrays in the shape ReadingRouteAnalyticsService reads
     */
    protected function rows(array $batchIds, ?int $districtId): Collection
    {
        if ($batchIds === []) {
            return collect();
        }

        return $this->scopeRouteStatsForActor(CommercialReadingRouteStat::query()->whereIn('batch_id', $batchIds))
            ->when($districtId !== null, fn ($query) => $query->where('district_id', $districtId))
            ->orderBy('id')
            ->toBase()
            ->get(['id', 'batch_id', 'route_id', 'district_id', 'cca_staff_id', 'cca_name_raw', 'employee_id', 'route_label_raw', 'verified_strength', 'read_count', 'skipped_count', 'visited_count', 'is_route_owner', 'is_flagged'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'b' => (int) $r->batch_id, 'route_id' => $r->route_id !== null ? (int) $r->route_id : null, 'district_id' => $r->district_id !== null ? (int) $r->district_id : null,
                'cca' => (string) $r->cca_staff_id, 'name' => $r->cca_name_raw, 'employee_id' => $r->employee_id !== null ? (int) $r->employee_id : null,
                'label' => $r->route_label_raw, 'strength' => (int) $r->verified_strength, 'read' => (int) $r->read_count, 'skipped' => (int) $r->skipped_count, 'visited' => (int) $r->visited_count,
                'owner' => (bool) $r->is_route_owner, 'flagged' => (bool) $r->is_flagged, 'cca_only' => $r->route_label_raw === null,
            ])->values();
    }

    /**
     * The districts that have rows (in the chosen uploads) the viewer may see: the choices of the district filter.
     *
     * @param  list<int>  $chosen
     * @return array<int, string>
     */
    protected function districtOptions(array $chosen): array
    {
        if ($chosen === []) {
            return [];
        }

        $ids = $this->scopeRouteStatsForActor(CommercialReadingRouteStat::query()->whereIn('batch_id', $chosen))->whereNotNull('district_id')->distinct()->toBase()->pluck('district_id')->map(fn ($id) => (int) $id)->all();

        return District::query()->whereIn('id', $ids)->orderBy('district_name')->pluck('district_name', 'id')->all();
    }

    protected function minReaders(): int
    {
        return max(1, (int) config('gwl.commercial_min_readers_for_district_figures', 3));
    }

    // ---------------------------------------------------------------- notes (the Billing range's sentences, for uploads of this report)

    /**
     * @param  array<string, mixed>  $selection
     * @param  list<int>  $chosen
     * @param  Collection<int, CommercialImportBatch>  $batches
     * @return list<string>
     */
    protected function notes(array $selection, array $chosen, Collection $batches): array
    {
        $notes = [];

        if (count($chosen) > 1) {
            $notes[] = count($chosen).' uploads that do not overlap are added up ('.implode(', ', array_map(fn ($id) => 'batch #'.$id, $chosen)).'): read, skipped and visited are summed, and each route\'s verified strength is the latest upload\'s, counted once.';
            $notes[] = 'A route read in more than one upload can be visited more often than its strength, so coverage is not capped at 100% and "unvisited" can be negative. It means the route was read again, not that the figures are wrong.';
        } elseif (count($chosen) === 1) {
            $notes[] = 'One upload lies inside this range (batch #'.$chosen[0].'). Coverage is visited divided by verified strength and is not capped at 100%.';
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
            if ($batches->has($shadow['id']) && $selection['from'] !== null && $batches[$shadow['id']]->period_to->toDateString() >= $selection['from'] && $batches[$shadow['id']]->period_from->toDateString() <= $selection['to']) {
                $notes[] = 'Batch #'.$shadow['id'].' is replaced by the newer batch #'.$shadow['by'].' ('.($shadow['reason'] === 'identical' ? 'the same period' : 'a period that contains it').').';
            }
        }

        if ($selection['partly_outside'] !== []) {
            $notes[] = 'Left out because their period is only partly inside the range: '.implode(', ', array_map(fn ($id) => '#'.$id.' ('.CommercialPeriods::describe($batches[$id]->period_from, $batches[$id]->period_to).')', array_filter($selection['partly_outside'], fn ($id) => $batches->has($id)))).'.';
        }

        return $notes;
    }

    // ---------------------------------------------------------------- R6: routes known elsewhere with no reading row

    /**
     * Routes the route master holds because the billing summary, the customer list or a manager filed them (not a reading report), that
     * have no reading row in the chosen uploads. Only the viewer's districts; the code that means "no route" is left out.
     *
     * @param  Collection<int, array<string, mixed>>  $chosenRows
     * @param  array<int, string>  $districtNames
     * @return array{total: int, shown: list<array<string, mixed>>, capped: bool}
     */
    protected function unreadRoutes(int $regionId, ?int $districtId, Collection $chosenRows, array $districtNames): array
    {
        $present = $chosenRows->where('cca_only', false)->pluck('route_id')->filter()->unique()->all();

        $query = $this->commercialScope()->limitDistricts(
            CommercialRoute::query()->where('region_id', $regionId)->whereNull('canonical_route_id')->where('active', true)
                ->whereIn('source', [CommercialRoute::SOURCE_BILLING, CommercialRoute::SOURCE_CUSTOMER_LIST, CommercialRoute::SOURCE_MANUAL])
                ->where(fn ($q) => $q->whereNull('code')->orWhere('code', '!=', CommercialRoute::UNASSIGNED_CODE))
                ->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId)),
            'district_id'
        );

        if ($present !== []) {
            // chunked so a long list never builds one huge IN (...)
            foreach (array_chunk($present, 500) as $chunk) {
                $query->whereNotIn('id', $chunk);
            }
        }

        $total = (clone $query)->count();
        $shown = $query->orderBy('district_id')->orderBy('code')->limit(ReadingRouteAnalyticsService::LIST_CAP)->get(['id', 'district_id', 'code', 'name', 'source'])
            ->map(fn (CommercialRoute $r) => ['route_id' => $r->id, 'label' => (string) ($r->code ?? $r->name), 'district_id' => $r->district_id, 'district' => $r->district_id !== null ? ($districtNames[$r->district_id] ?? District::query()->whereKey($r->district_id)->value('district_name')) : null, 'source' => $r->source])
            ->all();

        return ['total' => $total, 'shown' => $shown, 'capped' => $total > count($shown)];
    }

    // ---------------------------------------------------------------- R8: verified strength against the customer list

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    protected function strengthCheck(array $analysis, int $regionId, ?int $districtId): array
    {
        if (! config('gwl.commercial_customer_list_enabled')) {
            return ['available' => false, 'reason' => 'The customer list is switched off, so there are no metered accounts to compare with.'];
        }

        if (! $this->actorCan('commercial.view_customer_analytics')) {
            return ['available' => false, 'reason' => 'Comparing with the customer list needs the customer-list analytics permission.'];
        }

        $snapshots = app(CustomerSnapshots::class);
        $restriction = $this->customerRestriction();
        $districtIds = $this->customerDistrictIds();
        $current = $snapshots->current($restriction, $regionId, $districtId, null, $districtIds);

        if ($current->isEmpty()) {
            return ['available' => false, 'reason' => 'No customer list has been imported for this region yet.'];
        }

        $routeIds = collect($analysis['routes'])->where('placed', true)->pluck('route_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $filters = $snapshots->filters($current, $restriction, ['region_id' => $regionId], $districtIds);
        $metered = $routeIds === [] ? [] : app(CustomerAnalyticsService::class)->meteredByRoute($filters, $routeIds);

        // a code printed under more than one district of the region has aliases pointing at the canonical route: the join cannot tell them apart
        $ambiguous = [];

        foreach (array_chunk($routeIds, 500) as $chunk) {
            foreach (CommercialRoute::query()->whereIn('canonical_route_id', $chunk)->distinct()->pluck('canonical_route_id') as $id) {
                $ambiguous[(int) $id] = true;
            }
        }

        return $this->analytics->strengthAgainstCustomers($analysis['routes'], $metered, $ambiguous) + [
            'available' => true, 'customer_batches' => $current->count(), 'as_of' => $current->max('as_of_date') ? Carbon::parse($current->max('as_of_date'))->toDateString() : null,
        ];
    }

    /**
     * The counts everyone may see, whatever the small-group rule hides (taken from the full analysis, before any name is dropped).
     *
     * @param  array<string, mixed>  $a
     * @return array<string, int>
     */
    protected function counts(array $a): array
    {
        return [
            'ccas' => count($a['ccas']), 'ccas_with_routes' => count(array_filter($a['ccas'], fn (array $c) => $c['owned_routes'] > 0)),
            'ccas_zero_visits' => count($a['backlog']['ccas_zero_visits']), 'ccas_no_routes' => count($a['coverage']['ccas_no_routes']),
            'outliers' => count($a['outliers']['flagged']), 'workload_high' => count($a['workload']['high']), 'workload_low' => count($a['workload']['low']),
            'routes' => count(array_filter($a['routes'], fn (array $r) => $r['placed'])), 'multi_cca_routes' => count($a['coverage']['multi_cca_routes']),
        ];
    }

    // ---------------------------------------------------------------- the small-group rule

    /**
     * For a viewer who may not see individual CCAs: no CCA is named anywhere (counts only), and a district with fewer than the minimum of
     * active CCAs has its figures held back. A district user whose own district is that small is shown no figures at all.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    protected function smallGroupRule(array $report): array
    {
        $a = $report['analysis'];
        $min = $this->minReaders();
        $held = [];

        foreach ($a['districts'] as $d) {
            if ($d['active_ccas'] < $min) {
                $held[$d['id']] = $d['name'];
            }
        }

        $report['small_group'] = $this->actorDistrictLimited() && ($held !== [] || $a['districts'] === []);
        $report['held_back'] = array_values($held);

        if ($report['small_group']) {
            $report['analysis'] = null;
            $report['trend'] = [];
            $report['unread_routes'] = null;
            $report['strength'] = null;

            return $report;
        }

        $drop = fn (array $r) => ! in_array($r['district_id'], array_keys($held), true);

        $a['districts'] = array_map(function (array $d) use ($held): array {
            if (! isset($held[$d['id']])) {
                return $d + ['held_back' => false];
            }

            return array_merge($d, ['read' => null, 'skipped' => null, 'visited' => null, 'unvisited' => null, 'coverage' => null, 'skip_rate' => null, 'held_back' => true]);
        }, $a['districts']);

        $a['routes'] = array_map(fn (array $r) => array_merge($r, ['owner_cca' => null, 'owner_name' => null, 'ccas' => [], 'multi_rows' => []]), array_filter($a['routes'], $drop));
        $a['ccas'] = [];
        $a['workload']['rows'] = $a['workload']['high'] = $a['workload']['low'] = [];
        $a['workload']['largest_cca'] = null;
        $a['backlog']['routes'] = array_values(array_filter(array_map(fn (array $r) => array_merge($r, ['owner_cca' => null, 'owner_name' => null, 'ccas' => [], 'multi_rows' => []]), array_filter($a['backlog']['routes'], $drop))));
        $a['backlog']['routes_zero_visits'] = array_values(array_map(fn (array $r) => array_merge($r, ['owner_cca' => null, 'owner_name' => null, 'ccas' => [], 'multi_rows' => []]), array_filter($a['backlog']['routes_zero_visits'], $drop)));
        $a['backlog']['ccas'] = [];
        $a['backlog']['ccas_zero_visits'] = [];
        $a['coverage']['multi_cca_routes'] = array_values(array_map(fn (array $r) => array_merge($r, ['owner_cca' => null, 'owner_name' => null, 'ccas' => [], 'multi_rows' => []]), array_filter($a['coverage']['multi_cca_routes'], $drop)));
        $a['coverage']['ccas_no_routes'] = [];
        $a['outliers']['flagged'] = [];
        $a['routes'] = array_values($a['routes']);

        $report['analysis'] = $a;
        $report['trend'] = array_map(function (array $point) use ($held): array {
            $point['districts'] = array_map(fn (array $d) => isset($held[$d['id']]) ? array_merge($d, ['strength' => $d['strength'], 'visited' => null, 'skip_rate' => null, 'coverage' => null, 'held_back' => true]) : $d + ['held_back' => false], $point['districts']);

            return $point;
        }, $report['trend']);

        if ($report['strength'] && ($report['strength']['available'] ?? false)) {
            $report['strength']['beyond'] = array_values(array_filter($report['strength']['beyond'], $drop));
            $report['strength']['ambiguous'] = array_values(array_filter($report['strength']['ambiguous'], $drop));
        }

        return $report;
    }
}
