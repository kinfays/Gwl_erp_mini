<?php

namespace App\Services\Commercial\BillingDetails;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Models\CommercialBillingDetailBand;
use App\Models\CommercialBillingDetailIssue;
use App\Models\CommercialBillingDetailRollup;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingRouteStat;
use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\CommercialPeriods;
use App\Services\Commercial\RouteCcaResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Detailed Billing Report analyses (B1 to B13) for the signed-in user (Phase 8d, design 8.39): which uploads the typed range uses (the five
 * CommercialPeriods rules, exactly as the billing summary and the reading by route), the scoped rollup rows of those uploads collapsed over the
 * range (money flows added, the previous balance the EARLIEST period's, the balance due and every count of customers the LATEST period's), the
 * analyses (BillingDetailsAnalytics), the capped account lists, the trend by upload, and the CCA of each route. The Billing details screen and
 * the exports both get their data from here (through CommercialReportData::billingDetails()), so a file can never disagree with its page.
 *
 * Scope is the one scoping trait: a viewer reads only the uploads of the regions they may see, and a district user only their own district's rows
 * (a row with no district is not theirs: no region-wide row). A viewer without commercial.view_reader_performance sees no CCA name and has no CCA
 * filter (the Phase 8c rule).
 */
class BillingDetailsReport
{
    use ScopesCommercialByActor;

    /** The trend shows at most this many uploads (the newest). */
    public const TREND_LIMIT = 24;

    /** Accounts shown per exception list on the screen (the report holds every account of a list; an export takes up to the issue cap). */
    public const LIST_ROWS = 50;

    public function __construct(protected BillingDetailsAnalytics $analytics) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?int $regionId = null, ?int $districtId = null, ?string $category = null, ?string $ccaStaffId = null, ?int $routeId = null, ?string $from = null, ?string $to = null): array
    {
        $named = $this->actorCan('commercial.view_reader_performance');
        $base = [
            'allowed' => $this->actorCan('commercial.view_billing'), 'available' => false, 'named' => $named, 'regions' => [], 'region_id' => null, 'region' => null,
            'districts' => [], 'district_id' => null, 'categories' => [], 'category' => null, 'ccas' => [], 'cca' => null, 'routes_options' => [], 'route_id' => null,
            'month_options' => [], 'range' => null, 'notes' => [], 'analysis' => null, 'bands' => [], 'lists' => [], 'concentration' => null, 'integrity' => null, 'trend' => [],
            'district_limited' => $this->actorDistrictLimited(), 'cca_source' => null,
        ];

        if (! $base['allowed']) {
            return $base;
        }

        $batches = $this->scopeBatchesForActor(CommercialImportBatch::query())->ofType(CommercialImportBatch::TYPE_BILLING_DETAILS)->live()->get();

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

        // ---- the filters: each is honoured only for something the viewer may see in the chosen uploads
        $options = $this->options($chosen);
        $districtId = $districtId !== null && isset($options['districts'][$districtId]) ? $districtId : null;
        $category = $category !== null && $category !== '' && isset($options['categories'][$category]) ? $category : null;

        $cca = $this->ccaOwners($regionId, $selection['from'] ?? null, $selection['to'] ?? null);
        $ccaOptions = $named && $cca ? $this->ccaOptions($cca, $options['route_ids']) : [];
        $ccaStaffId = $named && $ccaStaffId !== null && isset($ccaOptions[$ccaStaffId]) ? $ccaStaffId : null;
        $routeId = $routeId !== null && isset($options['routes'][$routeId]) ? $routeId : null;

        $routeFilter = null;   // the canonical routes the rows are narrowed to (a route, a CCA's routes, or both)

        if ($routeId !== null) {
            $routeFilter = [$routeId];
        }

        if ($ccaStaffId !== null) {
            $owned = array_keys(array_filter($cca['owners'], fn (array $c) => $c['staff_id'] === $ccaStaffId));
            $routeFilter = $routeFilter === null ? $owned : array_values(array_intersect($routeFilter, $owned));
        }

        $filters = ['district' => $districtId, 'category' => $category, 'routes' => $routeFilter];

        // ---- the rows of the chosen uploads, collapsed over the range
        $rollups = $this->rollupRows($chosen, $filters);
        $ranks = $this->ranks($inRegion);
        $rows = $this->collapse($rollups, $ranks);

        $flags = [];

        foreach (DB::table('commercial_customer_statuses')->get(['code', 'is_active', 'is_billing']) as $s) {
            $flags[strtoupper((string) $s->code)] = ['active' => (bool) $s->is_active, 'billing' => (bool) $s->is_billing];
        }

        $categoryNames = DB::table('commercial_customer_categories')->whereNotNull('code')->pluck('name', 'code')->map(fn ($n) => (string) $n)->all();
        $districtNames = $options['districts'];
        $ctx = ['districts' => $districtNames, 'categories' => $categoryNames, 'flags' => $flags, 'cca' => $named && $cca ? $cca['owners'] : null, 'named' => $named];
        $analysis = $this->analytics->analyse($rows, $ctx);

        $bandRows = $routeFilter === null ? $this->bandRows($chosen, $filters, $ranks) : [];
        $analysis['bands'] = $this->analytics->bandTable($bandRows);
        $analysis['bands_by_district'] = $this->bandsByDistrict($bandRows, $districtNames);
        $analysis['band_note'] = $routeFilter === null ? null : 'The consumption bands are kept per district and service category, so a route or CCA filter does not narrow them.';

        $unitById = collect($units)->keyBy('id');
        $periodLabel = fn (int $id): string => CommercialPeriods::describe($unitById[$id]['from'], $unitById[$id]['to']);
        $label = CommercialPeriods::describe($selection['from'] ?? now()->toDateString(), $selection['to'] ?? now()->toDateString());

        return [
            'allowed' => true, 'available' => true, 'named' => $named, 'regions' => $regionNames, 'region_id' => $regionId, 'region' => $regionNames[$regionId] ?? null,
            'districts' => $options['districts'], 'district_id' => $districtId, 'categories' => $options['categories'], 'category' => $category,
            'ccas' => $ccaOptions, 'cca' => $ccaStaffId, 'routes_options' => $options['routes'], 'route_id' => $routeId, 'district_limited' => $this->actorDistrictLimited(),
            'month_options' => $this->monthOptions($units),
            'range' => [
                'from' => $selection['from'], 'to' => $selection['to'], 'label' => $label, 'typed' => $ranged, 'batch_ids' => $chosen,
                'covered_days' => $selection['covered_days'], 'range_days' => $selection['range_days'], 'gaps' => $selection['gaps'],
                'batches' => array_map(fn (int $id) => ['id' => $id, 'label' => $periodLabel($id), 'uploaded' => optional($inRegion[$id]->created_at)->toDateTimeString()], $chosen),
            ],
            'notes' => $this->notes($selection, $chosen, $inRegion, $filters),
            'analysis' => $analysis,
            'lists' => $this->lists($chosen, $filters, $districtNames, $cca, $named, $inRegion),
            'concentration' => $this->concentration($chosen, $inRegion, $districtId, $category, $routeFilter, $districtNames),
            'integrity' => $this->integrity($chosen, $inRegion, $analysis, $periodLabel),
            'trend' => $this->trend($trendIds, $filters, $districtNames, $periodLabel, $ranks),
            'cca_source' => $cca ? ['batch_id' => $cca['batch_id'], 'label' => $cca['label'], 'relation' => $cca['relation'], 'overlap_days' => $cca['overlap_days'], 'sentence' => $cca['sentence']] : null,
            'counts' => ['routes' => count($analysis['routes']), 'customers' => $analysis['totals']['customers'], 'districts' => count($analysis['districts'])],
        ];
    }

    // ---------------------------------------------------------------- the filters' choices

    /**
     * What the viewer may filter by: the districts, service categories and routes that have rows in the chosen uploads (and in the viewer's scope).
     *
     * @param  list<int>  $chosen
     * @return array{districts: array<int, string>, categories: array<string, string>, routes: array<int, string>, route_ids: list<int>}
     */
    protected function options(array $chosen): array
    {
        $out = ['districts' => [], 'categories' => [], 'routes' => [], 'route_ids' => []];

        if ($chosen === []) {
            return $out;
        }

        $rows = $this->scopeDetailRowsForActor(CommercialBillingDetailRollup::query()->whereIn('batch_id', $chosen))->toBase()
            ->select('district_id', 'category_code', 'route_id', 'route_label_raw')->distinct()->get();

        $out['districts'] = District::query()->whereIn('id', $rows->pluck('district_id')->filter()->unique()->all())->orderBy('district_name')->pluck('district_name', 'id')->all();

        $categoryNames = DB::table('commercial_customer_categories')->whereNotNull('code')->pluck('name', 'code')->all();

        foreach ($rows->pluck('category_code')->unique()->sort() as $code) {
            $out['categories'][(string) $code] = (string) $code === '' ? '(no category)' : (string) $code.(isset($categoryNames[$code]) ? ' '.$categoryNames[$code] : '');
        }

        foreach ($rows->whereNotNull('route_id')->unique('route_id')->sortBy('route_label_raw') as $r) {
            $out['routes'][(int) $r->route_id] = (string) $r->route_label_raw;
            $out['route_ids'][] = (int) $r->route_id;
        }

        return $out;
    }

    // ---------------------------------------------------------------- the CCA of a route (RouteCcaResolver, Phase 8e step 0.1)

    /**
     * The owner of each route in the reading-by-route upload that overlaps the BILL period the most (the newest when none does): one rule,
     * RouteCcaResolver, shared with the cross-file analyses.
     *
     * @return array{owners: array<int, array{staff_id: string, name: string|null}>, batch_id: int, label: string, relation: string, overlap_days: int, sentence: string, from: string, to: string}|null
     */
    protected function ccaOwners(int $regionId, ?string $billFrom, ?string $billTo): ?array
    {
        return app(RouteCcaResolver::class)->forPeriod($regionId, $billFrom, $billTo);
    }

    /**
     * The CCAs that own at least one route of the chosen uploads, as the filter's choices (staff id => "id name").
     *
     * @param  array{owners: array<int, array{staff_id: string, name: string|null}>}  $cca
     * @param  list<int>  $routeIds
     * @return array<string, string>
     */
    protected function ccaOptions(array $cca, array $routeIds): array
    {
        $out = [];

        foreach ($routeIds as $id) {
            if (isset($cca['owners'][$id])) {
                $out[$cca['owners'][$id]['staff_id']] = trim($cca['owners'][$id]['staff_id'].' '.$cca['owners'][$id]['name']);
            }
        }

        asort($out);

        return $out;
    }

    // ---------------------------------------------------------------- rows

    /**
     * @param  list<int>  $batchIds
     * @param  array{district: int|null, category: string|null, routes: list<int>|null}  $filters
     * @return Collection<int, object>
     */
    protected function rollupRows(array $batchIds, array $filters): Collection
    {
        if ($batchIds === []) {
            return collect();
        }

        return $this->scopeDetailRowsForActor(CommercialBillingDetailRollup::query()->whereIn('batch_id', $batchIds))
            ->when($filters['district'] !== null, fn ($q) => $q->where('district_id', $filters['district']))
            ->when($filters['category'] !== null, fn ($q) => $q->where('category_code', $filters['category']))
            ->when($filters['routes'] !== null, fn ($q) => $filters['routes'] === [] ? $q->whereRaw('1 = 0') : $q->whereIn('route_id', $filters['routes']))
            ->toBase()->get();
    }

    /**
     * The order of the uploads of a region by period (then id): batch id => position. The earliest period is the first.
     *
     * @param  Collection<int, CommercialImportBatch>  $inRegion
     * @return array<int, int>
     */
    protected function ranks(Collection $inRegion): array
    {
        $order = $inRegion->sortBy(fn (CommercialImportBatch $b) => [$b->period_from->toDateString(), $b->period_to->toDateString(), $b->id])->pluck('id')->values()->all();

        return array_flip($order);
    }

    /**
     * Collapses the rollup rows of the chosen uploads into ONE set (CommercialPeriods rule 4): money and consumption (the flows) are added over
     * the uploads; the previous balance is the EARLIEST upload's value for that route; every count of customers and the balance due are the LATEST
     * upload's (absent from it = none); BILLS adds the customers over the uploads (the denominator of a per-bill figure). With one upload the rows
     * are that upload's, unchanged.
     *
     * @param  Collection<int, object>  $rows
     * @param  array<int, int>  $ranks
     * @return list<array<string, mixed>>
     */
    protected function collapse(Collection $rows, array $ranks): array
    {
        $earliest = [];
        $latest = [];

        foreach ($rows as $r) {
            $unit = $this->unit($r);
            $rank = $ranks[(int) $r->batch_id] ?? 0;

            if (! isset($earliest[$unit]) || $rank < $earliest[$unit]) {
                $earliest[$unit] = $rank;
            }

            if (! isset($latest[$unit]) || $rank > $latest[$unit]) {
                $latest[$unit] = $rank;
            }
        }

        $flows = array_flip(BillingDetailsAnalytics::FLOWS);
        $first = array_flip(BillingDetailsAnalytics::EARLIEST);
        $out = [];

        foreach ($rows as $r) {
            $unit = $this->unit($r);
            $rank = $ranks[(int) $r->batch_id] ?? 0;
            $key = $unit."\x1f".$r->category_code."\x1f".$r->customer_status."\x1f".$r->meter_status."\x1f".$r->bill_status;
            $entry = &$out[$key];
            $entry ??= [
                'route_id' => $r->route_id !== null ? (int) $r->route_id : null, 'label' => (string) $r->route_label_raw, 'district_id' => $r->district_id !== null ? (int) $r->district_id : null,
                'cat' => (string) $r->category_code, 'cs' => (string) $r->customer_status, 'ms' => (string) $r->meter_status, 'bs' => (string) $r->bill_status, 'v' => array_fill(0, 33, 0),
            ];
            $vector = $this->vector($r);
            $entry['v'][BillingDetailsAnalytics::BILLS] += $vector[BillingDetailsScan::C_CUSTOMERS];

            foreach ($vector as $i => $value) {
                if (isset($flows[$i])) {
                    $entry['v'][$i] += $value;
                } elseif (isset($first[$i])) {
                    $entry['v'][$i] += $rank === $earliest[$unit] ? $value : 0;
                } else {
                    $entry['v'][$i] += $rank === $latest[$unit] ? $value : 0;
                }
            }

            unset($entry);
        }

        return array_values($out);
    }

    protected function unit(object $r): string
    {
        return $r->route_id !== null ? 'r'.$r->route_id : 'l'.$r->route_label_raw;
    }

    /** One rollup row as the packed vector (money in pesewas, consumption in thousandths, counts as they are). @return array<int, int> */
    protected function vector(object $r): array
    {
        $v = [];

        foreach (BillingDetailsScan::COLUMNS as $i => $column) {
            $value = $r->{$column};
            $v[$i] = match (true) {
                $i === BillingDetailsScan::C_CONSUME => (int) round((float) $value * 1000),
                in_array($i, BillingDetailsScan::MONEY, true) => (int) round((float) $value * 100),
                default => (int) $value,
            };
        }

        return $v;
    }

    // ---------------------------------------------------------------- bands (B4)

    /**
     * The consumption bands of the chosen uploads, collapsed the same way (consumption added, customers the latest upload's per district).
     *
     * @param  list<int>  $chosen
     * @param  array<string, mixed>  $filters
     * @param  array<int, int>  $ranks
     * @return list<array{district_id: int|null, cat: string, band: string, customers: int, consume: int}>
     */
    protected function bandRows(array $chosen, array $filters, array $ranks): array
    {
        if ($chosen === []) {
            return [];
        }

        $rows = $this->scopeDetailRowsForActor(CommercialBillingDetailBand::query()->whereIn('batch_id', $chosen))
            ->when($filters['district'] !== null, fn ($q) => $q->where('district_id', $filters['district']))
            ->when($filters['category'] !== null, fn ($q) => $q->where('category_code', $filters['category']))
            ->toBase()->get();

        $latest = [];

        foreach ($rows as $r) {
            $rank = $ranks[(int) $r->batch_id] ?? 0;
            $latest[$r->district_key] = max($latest[$r->district_key] ?? -1, $rank);
        }

        $out = [];

        foreach ($rows as $r) {
            $key = $r->district_key."\x1f".$r->category_code."\x1f".$r->band;
            $entry = &$out[$key];
            $entry ??= ['district_id' => $r->district_id !== null ? (int) $r->district_id : null, 'cat' => (string) $r->category_code, 'band' => (string) $r->band, 'customers' => 0, 'consume' => 0];
            $entry['consume'] += (int) round((float) $r->consume * 1000);
            $entry['customers'] += ($ranks[(int) $r->batch_id] ?? 0) === $latest[$r->district_key] ? (int) $r->customers : 0;
            unset($entry);
        }

        return array_values($out);
    }

    /**
     * @param  list<array<string, mixed>>  $bands
     * @param  array<int, string>  $districtNames
     * @return list<array<string, mixed>>
     */
    protected function bandsByDistrict(array $bands, array $districtNames): array
    {
        $by = [];

        foreach ($bands as $b) {
            $by[$b['district_id'] ?? 0][] = $b;
        }

        $out = [];

        foreach ($by as $id => $list) {
            $out[] = ['id' => $id ?: null, 'name' => $id ? ($districtNames[$id] ?? 'District '.$id) : 'District not known yet', 'bands' => $this->analytics->bandTable($list)];
        }

        usort($out, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    // ---------------------------------------------------------------- the capped lists

    /**
     * The account lists of the chosen uploads, one per kind: the largest figure first, an account once (its largest figure), narrowed by the
     * district and the route / CCA filter (the lists carry no category). Account numbers only; opening one uses the customer view and its own
     * permissions. A list of an upload older than the keep period has been purged and is simply empty.
     *
     * @param  list<int>  $chosen
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $districtNames
     * @param  array<string, mixed>|null  $cca
     * @param  Collection<int, CommercialImportBatch>  $inRegion
     * @return array<string, array<string, mixed>>
     */
    protected function lists(array $chosen, array $filters, array $districtNames, ?array $cca, bool $named, Collection $inRegion): array
    {
        $out = [];

        foreach (CommercialBillingDetailIssue::KINDS as $kind => $amountLabel) {
            $out[$kind] = ['kind' => $kind, 'amount_label' => $amountLabel, 'analysis' => BillingDetailsAnalytics::LIST_KINDS[$kind], 'total' => 0, 'rows' => [], 'capped' => false];
        }

        if ($chosen === []) {
            return $out;
        }

        $issues = $this->scopeDetailRowsForActor(CommercialBillingDetailIssue::query()->whereIn('batch_id', $chosen))
            ->when($filters['district'] !== null, fn ($q) => $q->where('district_id', $filters['district']))
            ->when($filters['routes'] !== null, fn ($q) => $filters['routes'] === [] ? $q->whereRaw('1 = 0') : $q->whereIn('route_id', $filters['routes']))
            ->toBase()->get(['kind', 'account_no', 'route_id', 'route_label_raw', 'district_id', 'amount']);

        $byKind = [];

        foreach ($issues as $i) {
            $current = $byKind[$i->kind][$i->account_no] ?? null;

            if ($current === null || abs((float) $i->amount) > abs((float) $current->amount)) {
                $byKind[$i->kind][$i->account_no] = $i;
            }
        }

        foreach ($byKind as $kind => $accounts) {
            if (! isset($out[$kind])) {
                continue;
            }

            $list = array_values($accounts);
            usort($list, fn (object $a, object $b) => [abs((float) $b->amount), $a->account_no] <=> [abs((float) $a->amount), $b->account_no]);
            $out[$kind]['total'] = count($list);
            $out[$kind]['capped'] = count($list) > self::LIST_ROWS;
            $out[$kind]['rows'] = array_map(function (object $i) use ($districtNames, $cca, $named): array {
                $owner = $named && $cca && $i->route_id !== null ? ($cca['owners'][(int) $i->route_id] ?? null) : null;

                return [
                    'account' => (string) $i->account_no, 'route' => (string) $i->route_label_raw, 'district' => $i->district_id !== null ? ($districtNames[(int) $i->district_id] ?? null) : null,
                    'amount' => (float) $i->amount, 'cca' => $owner ? trim($owner['staff_id'].' '.$owner['name']) : null,
                ];
            }, $list);
        }

        return $out;
    }

    // ---------------------------------------------------------------- B7: the share held by the top 10 percent of owing accounts

    /**
     * From the LATEST chosen upload (a balance is a stock): the owed balance, and how much of it the largest 10 percent of owing accounts hold.
     * It exists for a district, a service category, both, or the whole file; it cannot be narrowed to a route or a CCA, and a district user with
     * more than one district has no figure for "all of them" (it would include districts they may not see).
     *
     * @param  list<int>  $chosen
     * @param  Collection<int, CommercialImportBatch>  $inRegion
     * @param  array<int, string>  $districtNames
     * @return array<string, mixed>
     */
    protected function concentration(array $chosen, Collection $inRegion, ?int $districtId, ?string $category, ?array $routeFilter, array $districtNames): array
    {
        if ($chosen === []) {
            return ['available' => false, 'reason' => 'No upload is in the range.'];
        }

        if ($routeFilter !== null) {
            return ['available' => false, 'reason' => 'This share is worked out for the whole file, a district or a service category: it cannot be narrowed to a route or a CCA.'];
        }

        $latest = $inRegion->only($chosen)->sortBy(fn (CommercialImportBatch $b) => [$b->period_to->toDateString(), $b->id])->last();
        $district = $districtId !== null ? (string) $districtId : '*';

        if ($districtId === null && $this->actorDistrictLimited()) {
            $mine = $this->actorDistrictIds() ?? [];

            if (count($mine) !== 1) {
                return ['available' => false, 'reason' => 'Choose a district: the share across all of your districts is not worked out.'];
            }

            $district = (string) $mine[0];
        }

        foreach ($latest->control_totals['concentration'] ?? [] as $entry) {
            if ((string) $entry['district'] === $district && (string) $entry['category'] === ($category ?? '*')) {
                return [
                    'available' => true, 'batch_id' => $latest->id, 'owing' => (int) $entry['owing'], 'amount' => (float) $entry['amount'], 'top_n' => (int) $entry['top_n'], 'top_amount' => (float) $entry['top_amount'],
                    'share' => $entry['amount'] > 0 ? round($entry['top_amount'] / $entry['amount'] * 100, 2) : null,
                ];
            }
        }

        return ['available' => false, 'reason' => 'No customer owes anything here, so there is no share to show.'];
    }

    // ---------------------------------------------------------------- B12: integrity

    /**
     * The route reconciliation of each chosen upload (the routes whose rows disagreed with their own totals row) and the counts of the other
     * checks, for the viewer's scope.
     *
     * @param  list<int>  $chosen
     * @param  Collection<int, CommercialImportBatch>  $inRegion
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    protected function integrity(array $chosen, Collection $inRegion, array $analysis, callable $periodLabel): array
    {
        $limited = $this->actorDistrictIds();
        $batches = [];

        foreach ($chosen as $id) {
            $control = $inRegion[$id]->control_totals ?? [];
            $flagged = array_values(array_filter($control['flagged_routes'] ?? [], fn (array $r) => $limited === null || ($r['district_id'] !== null && in_array((int) $r['district_id'], $limited, true))));
            $batches[] = [
                'id' => $id, 'period' => $periodLabel($id), 'routes_checked' => $limited === null ? (int) ($control['routes_checked'] ?? 0) : null, 'routes_failed' => count($flagged),
                'flagged' => array_map(fn (array $r) => ['label' => $r['label'], 'diffs' => $r['diffs']], array_slice($flagged, 0, 50)),
                'duplicates' => $limited === null ? (int) ($control['scan']['duplicates'] ?? 0) : null,
                'link' => $limited === null ? ($control['link'] ?? null) : null,
            ];
        }

        $t = $analysis['totals'];

        return ['batches' => $batches, 'components_off' => $t['components_off'], 'negative_consume' => $t['negative_consume'], 'bill_month_outside' => $t['bill_month_outside']];
    }

    // ---------------------------------------------------------------- trend (B13)

    /**
     * One point per upload (each alone, labelled with its dates): the totals and the districts' figures, narrowed by the same filters. Worked out
     * from grouped sums in the database, so a long history never loads its rollup rows.
     *
     * @param  list<int>  $trendIds
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $districtNames
     * @param  array<int, int>  $ranks
     * @return list<array<string, mixed>>
     */
    protected function trend(array $trendIds, array $filters, array $districtNames, callable $periodLabel, array $ranks): array
    {
        if ($trendIds === []) {
            return [];
        }

        $sums = [];

        foreach (BillingDetailsScan::COLUMNS as $i => $column) {
            $sums[$column] = 'SUM('.$column.')';
        }

        $query = $this->scopeDetailRowsForActor(CommercialBillingDetailRollup::query()->whereIn('batch_id', $trendIds))
            ->when($filters['district'] !== null, fn ($q) => $q->where('district_id', $filters['district']))
            ->when($filters['category'] !== null, fn ($q) => $q->where('category_code', $filters['category']))
            ->when($filters['routes'] !== null, fn ($q) => $filters['routes'] === [] ? $q->whereRaw('1 = 0') : $q->whereIn('route_id', $filters['routes']))
            ->toBase()
            ->selectRaw('batch_id, district_id, bill_status, '.implode(', ', array_map(fn (string $column, string $sql) => $sql.' AS '.$column, array_keys($sums), $sums)))
            ->groupBy('batch_id', 'district_id', 'bill_status');

        $byBatch = [];

        foreach ($query->get() as $r) {
            $v = $this->vector($r);
            $byBatch[(int) $r->batch_id][] = ['route_id' => null, 'label' => '', 'district_id' => $r->district_id !== null ? (int) $r->district_id : null, 'cat' => '', 'cs' => '', 'ms' => '', 'bs' => (string) $r->bill_status, 'v' => [...$v, BillingDetailsAnalytics::BILLS => $v[BillingDetailsScan::C_CUSTOMERS]]];
        }

        $ctx = ['districts' => $districtNames, 'categories' => [], 'flags' => [], 'cca' => null, 'named' => false];
        $points = [];

        foreach ($trendIds as $id) {
            $analysis = $this->analytics->analyse($byBatch[$id] ?? [], $ctx);
            $t = $analysis['totals'];
            $points[] = [
                'batch_id' => $id, 'label' => $periodLabel($id),
                'customers' => $t['customers'], 'billed_customers' => $t['billed_customers'], 'estimated_share' => $t['estimated_share'], 'unbilled_share' => $t['unbilled_share'],
                'consume' => $t['consume'], 'consume_per_bill' => $t['consume_per_bill'], 'month_total' => $t['month_total'], 'paid' => $t['paid'], 'collection_ratio' => $t['collection_ratio'],
                'balance_due' => $t['balance_due'], 'owing_amount' => $t['owing_amount'], 'credit_amount' => $t['credit_amount'], 'adjusted' => $t['adjusted'], 'billed_unpaid' => $t['billed_unpaid'],
                'billed_unpaid_amount' => $t['billed_unpaid_amount'], 'tariff_mismatch_pct' => $t['tariff_mismatch_pct'], 'read_over_90_share' => $t['read_over_90_share'],
                'districts' => array_map(fn (array $d) => ['id' => $d['id'], 'name' => $d['name'], 'customers' => $d['customers'], 'estimated_share' => $d['estimated_share'], 'unbilled_share' => $d['unbilled_share'], 'month_total' => $d['month_total'], 'paid' => $d['paid'], 'collection_ratio' => $d['collection_ratio'], 'balance_due' => $d['balance_due']], $analysis['districts']),
            ];
        }

        return $points;
    }

    // ---------------------------------------------------------------- notes (the Billing range's sentences, for uploads of this report)

    /**
     * @param  array<string, mixed>  $selection
     * @param  list<int>  $chosen
     * @param  Collection<int, CommercialImportBatch>  $batches
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    protected function notes(array $selection, array $chosen, Collection $batches, array $filters): array
    {
        $notes = [];

        if (count($chosen) > 1) {
            $notes[] = count($chosen).' uploads that do not overlap are added up ('.implode(', ', array_map(fn ($id) => 'batch #'.$id, $chosen)).'): consumption, charges, the month total, payments and adjustments are summed; the previous balance is the EARLIEST upload\'s; the balance due and every count of customers are the LATEST upload\'s.';
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
            if ($batches->has($shadow['id']) && $selection['from'] !== null && $batches[$shadow['id']]->period_to->toDateString() >= $selection['from'] && $batches[$shadow['id']]->period_from->toDateString() <= $selection['to']) {
                $notes[] = 'Batch #'.$shadow['id'].' is replaced by the newer batch #'.$shadow['by'].' ('.($shadow['reason'] === 'identical' ? 'the same period' : 'a period that contains it').').';
            }
        }

        if ($selection['partly_outside'] !== []) {
            $notes[] = 'Left out because their period is only partly inside the range: '.implode(', ', array_map(fn ($id) => '#'.$id.' ('.CommercialPeriods::describe($batches[$id]->period_from, $batches[$id]->period_to).')', array_filter($selection['partly_outside'], fn ($id) => $batches->has($id)))).'.';
        }

        if ($filters['category'] !== null) {
            $notes[] = 'The account lists carry no service category, so the category filter does not narrow them.';
        }

        $cutoff = now()->subMonths(max(1, (int) config('gwl.commercial_billing_detail_issue_keep_months', 3)));

        if ($chosen !== [] && collect($chosen)->contains(fn (int $id) => $batches[$id]->created_at && $batches[$id]->created_at->lt($cutoff))) {
            $notes[] = 'The account lists of an upload older than '.(int) config('gwl.commercial_billing_detail_issue_keep_months', 3).' months have been deleted (the figures are kept).';
        }

        return $notes;
    }

    /**
     * The calendar months the region's uploads reach (newest first, at most 36): the "a whole month" shortcut of the range control.
     *
     * @param  list<array{id: int|string, from: string, to: string, order: int}>  $units
     * @return array<string, string> Y-m => 'Sep 2026'
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
}
