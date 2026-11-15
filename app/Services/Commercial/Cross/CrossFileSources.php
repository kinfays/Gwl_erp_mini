<?php

namespace App\Services\Commercial\Cross;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Models\CommercialBillingDetailDistrictCell;
use App\Models\CommercialBillingDetailIssue;
use App\Models\CommercialBillingDetailTransition;
use App\Models\CommercialImportBatch;
use App\Models\CommercialRoute;
use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\BillingDetails\BillingDetailsReport;
use App\Services\Commercial\CommercialPeriods;
use App\Services\Commercial\Customers\CustomerAnalyticsService;
use App\Services\Commercial\Customers\CustomerSnapshots;
use App\Services\Commercial\ReadingRouteReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The data behind the analyses that need MORE THAN ONE FILE (Phase 8e, design 8.42). It reads nothing itself that a single-file screen does
 * not already read: the reading side IS ReadingRouteReport::build(), the billing side IS BillingDetailsReport::build() (so the scope, the
 * small-group rule, the period model and the CCA permission are theirs, and a figure here can never disagree with its own page), and the
 * customer side is the route-level and district-level ROLLUPS of the customer list (never the customers table), plus the counts the billing
 * link stored (transitions, the district matrix).
 *
 * THE WINDOW RULE. The three files are at different points in time, so every side says which period it used.
 *   "latest of each" (the default): each side uses its OWN newest live upload inside the typed range, or its newest overall when no range is typed.
 *   "same range": each side uses the typed range exactly as its own screen would (several uploads that do not overlap are added up).
 * A side with no upload (or none inside the range) is "not available" and says so; nothing is ever invented.
 *
 * THE PERMISSION RULE. Reading figures need commercial.view_reading, billing figures commercial.view_billing, customer-list figures
 * commercial.view_customer_analytics (and the customer-list flag); a CCA's NAME needs commercial.view_reader_performance. A side the viewer may
 * not have is not loaded at all: the bundle says `allowed => false` for it and the analyses show the blocks that remain with a line naming what
 * is hidden. The link (transitions, matrix, accounts in one file and not the other) compares billing with the list, so it needs both.
 */
class CrossFileSources
{
    use ScopesCommercialByActor;

    public const PERMISSIONS = [
        'reading' => 'commercial.view_reading',
        'billing' => 'commercial.view_billing',
        'customers' => 'commercial.view_customer_analytics',
    ];

    public const SOURCE_TITLES = ['reading' => 'Reading by route and CCA', 'billing' => 'Detailed billing', 'customers' => 'Customer list', 'link' => 'Billing linked to the customer list'];

    public function __construct(
        protected ReadingRouteReport $reading,
        protected BillingDetailsReport $billing,
        protected CustomerSnapshots $snapshots,
        protected CustomerAnalyticsService $customers,
    ) {}

    /**
     * @param  array{region?: int|null, district?: int|null, from?: string|null, to?: string|null, mode?: string|null}  $q
     * @param  list<string>  $needs  which sides the caller will use: reading, billing, customers, link
     * @return array<string, mixed>
     */
    public function load(array $q, array $needs = ['reading', 'billing', 'customers', 'link']): array
    {
        $mode = ($q['mode'] ?? 'latest') === 'same' ? 'same' : 'latest';
        $from = isset($q['from']) && $q['from'] !== '' ? (string) $q['from'] : null;
        $to = isset($q['to']) && $q['to'] !== '' ? (string) $q['to'] : null;
        $typed = CommercialPeriods::bound($from, false) !== null || CommercialPeriods::bound($to, true) !== null;

        $can = [
            'reading' => $this->actorCan(self::PERMISSIONS['reading']),
            'billing' => $this->actorCan(self::PERMISSIONS['billing']),
            'customers' => $this->actorCan(self::PERMISSIONS['customers']) && (bool) config('gwl.commercial_customer_list_enabled'),
            'named' => $this->actorCan('commercial.view_reader_performance'),
        ];

        $bundle = [
            'regions' => [], 'region_id' => null, 'region' => null, 'districts' => [], 'district_id' => null, 'district_limited' => $this->actorDistrictLimited(),
            'mode' => $mode, 'typed' => $typed, 'from' => $from, 'to' => $to, 'month_options' => [], 'can' => $can,
            'windows' => [], 'reading' => null, 'billing' => null, 'customers' => null, 'link' => null, 'cca' => null, 'notes' => [], 'any' => false,
        ];

        $regions = $this->regionChoices();

        if ($regions === []) {
            foreach (['reading', 'billing', 'customers', 'link'] as $source) {
                $bundle['windows'][$source] = $this->window($source, $this->allowed($source, $can), false, null, 'No upload has been loaded for this part yet.');
            }

            return $bundle;
        }

        $regionId = isset($q['region']) && isset($regions[(int) $q['region']]) ? (int) $q['region'] : (int) array_key_first($regions);
        $bundle['regions'] = $regions;
        $bundle['region_id'] = $regionId;
        $bundle['region'] = $regions[$regionId];

        $districts = $this->scopeDistrictsForActor(District::query()->where('region_id', $regionId))->orderBy('district_name')->pluck('district_name', 'id')->all();
        $districtId = isset($q['district']) && isset($districts[(int) $q['district']]) ? (int) $q['district'] : null;
        $bundle['districts'] = $districts;
        $bundle['district_id'] = $districtId;

        foreach (['reading', 'billing'] as $source) {
            $bundle['windows'][$source] = $this->window($source, $can[$source], false, null, null);
        }

        $bundle['windows']['customers'] = $this->window('customers', $can['customers'], false, null, null);
        $bundle['windows']['link'] = $this->window('link', $can['billing'] && $can['customers'], false, null, null);

        if (in_array('reading', $needs, true) && $can['reading']) {
            $this->loadReading($bundle, $regionId, $districtId, $from, $to, $mode, $typed);
        }

        if ((in_array('billing', $needs, true) || in_array('link', $needs, true)) && $can['billing']) {
            $this->loadBilling($bundle, $regionId, $districtId, $from, $to, $mode, $typed);
        }

        if (in_array('customers', $needs, true) && $can['customers']) {
            $this->loadCustomers($bundle, $regionId, $districtId, $from, $to, $typed);
        }

        if (in_array('link', $needs, true) && $can['billing'] && $can['customers'] && $bundle['billing'] !== null) {
            $this->loadLink($bundle, $districtId);
        }

        $bundle['any'] = $bundle['reading'] !== null || $bundle['billing'] !== null || $bundle['customers'] !== null;

        return $bundle;
    }

    // ---------------------------------------------------------------- regions

    /**
     * The regions that have a live upload of any of the three reports the viewer may see (id => name).
     *
     * @return array<int, string>
     */
    protected function regionChoices(): array
    {
        $ids = [];
        $newest = [];

        $batches = $this->scopeBatchesForActor(CommercialImportBatch::query())
            ->whereIn('report_type', [CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY, CommercialImportBatch::TYPE_BILLING_DETAILS])
            ->whereNotNull('region_id')->get(['id', 'region_id', 'report_type', 'status']);

        foreach ($batches as $b) {
            if ($b->status === CommercialImportBatch::STATUS_VOIDED) {
                continue;
            }

            $ids[(int) $b->region_id] = true;
            $newest[(int) $b->region_id] = max($newest[(int) $b->region_id] ?? 0, (int) $b->id);
        }

        $restriction = $this->customerRestriction();

        if (config('gwl.commercial_customer_list_enabled') && $restriction !== 0) {
            foreach ($this->snapshots->scoped($restriction, $this->customerDistrictIds())->whereNotNull('region_id')->get(['id', 'region_id']) as $c) {
                $ids[(int) $c->region_id] = true;
                $newest[(int) $c->region_id] ??= 0;
            }
        }

        if ($ids === []) {
            return [];
        }

        $names = Region::query()->whereIn('id', array_keys($ids))->pluck('region_name', 'id')->all();

        // the region of the newest route-grain upload first (the default), then by name
        uksort($names, fn ($a, $b) => [-($newest[$a] ?? 0), $names[$a]] <=> [-($newest[$b] ?? 0), $names[$b]]);

        return $names;
    }

    // ---------------------------------------------------------------- the windows

    /**
     * @return array<string, mixed>
     */
    protected function window(string $source, bool $allowed, bool $available, ?array $data, ?string $reason): array
    {
        return [
            'source' => $source, 'title' => self::SOURCE_TITLES[$source], 'allowed' => $allowed, 'available' => $available, 'reason' => $reason,
            'label' => $data['label'] ?? null, 'sentence' => $data['sentence'] ?? null, 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null,
            'batch_ids' => $data['batch_ids'] ?? [], 'uploads' => $data['uploads'] ?? [], 'notes' => $data['notes'] ?? [],
            'permission' => self::PERMISSIONS[$source] ?? null,
        ];
    }

    /** @param  array<string, bool>  $can */
    protected function allowed(string $source, array $can): bool
    {
        return $source === 'link' ? ($can['billing'] && $can['customers']) : (bool) ($can[$source] ?? false);
    }

    /**
     * What to ask a route-grain report for under the window rule: the dates to pass to its build(), or null when no upload lies inside the typed range.
     *
     * @return array{from: string|null, to: string|null}|null
     */
    protected function span(string $type, int $regionId, ?string $from, ?string $to, string $mode, bool $typed): ?array
    {
        if (! $typed || $mode === 'same') {
            return ['from' => $from, 'to' => $to];
        }

        $batches = CommercialImportBatch::query()->ofType($type)->where('region_id', $regionId)
            ->when($type === CommercialImportBatch::TYPE_BILLING_DETAILS, fn ($q) => $q->live(), fn ($q) => $q->notVoided())->get();
        $units = $batches->map(fn (CommercialImportBatch $b) => ['id' => $b->id, 'from' => $b->period_from->toDateString(), 'to' => $b->period_to->toDateString(), 'order' => $b->id])->values()->all();
        $selection = (new CommercialPeriods)->select($units, CommercialPeriods::bound($from, false)?->toDateString(), CommercialPeriods::bound($to, true)?->toDateString());

        if ($selection['chosen'] === []) {
            return null;
        }

        $byId = collect($units)->keyBy('id');
        $newest = collect($selection['chosen'])->map(fn ($id) => $byId[$id])->sortBy(fn (array $u) => [$u['to'], $u['order']])->last();

        return ['from' => $newest['from'], 'to' => $newest['to']];
    }

    // ---------------------------------------------------------------- the reading side

    protected function loadReading(array &$bundle, int $regionId, ?int $districtId, ?string $from, ?string $to, string $mode, bool $typed): void
    {
        $span = $this->span(CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY, $regionId, $from, $to, $mode, $typed);

        if ($span === null) {
            $bundle['windows']['reading'] = $this->window('reading', true, false, null, 'No reading-by-route upload lies inside the range.');

            return;
        }

        $r = $this->reading->build($regionId, $districtId, $span['from'], $span['to']);

        if (! $r['available']) {
            $bundle['windows']['reading'] = $this->window('reading', true, false, null, 'No reading-by-route report has been loaded for this region yet.');

            return;
        }

        $range = $r['range'];
        $bundle['windows']['reading'] = $this->window('reading', true, true, [
            'label' => $range['label'], 'from' => $range['from'], 'to' => $range['to'], 'batch_ids' => $range['batch_ids'], 'uploads' => $range['batches'], 'notes' => $r['notes'],
            'sentence' => 'Reading by route and CCA: '.$range['label'].' ('.implode(', ', array_map(fn (array $b) => 'batch #'.$b['id'], $range['batches'])).')',
        ], null);

        $a = $r['analysis'];
        $routes = [];
        $unplaced = [];

        foreach ($a['routes'] ?? [] as $route) {
            if ($route['placed'] && $route['route_id'] !== null) {
                $routes[(int) $route['route_id']] = $route;
            } else {
                $unplaced[] = $route;
            }
        }

        $bundle['reading'] = [
            'routes' => $routes, 'unplaced' => $unplaced, 'ccas' => $a['ccas'] ?? [], 'districts' => $a['districts'] ?? [], 'totals' => $a['totals'] ?? null,
            'outliers' => $a['outliers'] ?? ['flagged' => [], 'eligible' => 0], 'backlog' => $a['backlog'] ?? null, 'coverage' => $a['coverage'] ?? null, 'min_visits' => $a['min_visits'] ?? null,
            'held_back' => $r['held_back'] ?? [], 'small_group' => (bool) ($r['small_group'] ?? false), 'counts' => $r['counts'] ?? [], 'named' => (bool) $r['named'],
            'batch_ids' => $range['batch_ids'], 'range' => $range,
        ];
    }

    // ---------------------------------------------------------------- the billing side

    protected function loadBilling(array &$bundle, int $regionId, ?int $districtId, ?string $from, ?string $to, string $mode, bool $typed): void
    {
        $span = $this->span(CommercialImportBatch::TYPE_BILLING_DETAILS, $regionId, $from, $to, $mode, $typed);

        if ($span === null) {
            $bundle['windows']['billing'] = $this->window('billing', true, false, null, 'No detailed billing upload lies inside the range.');

            return;
        }

        $b = $this->billing->build($regionId, $districtId, null, null, null, $span['from'], $span['to']);

        if (! $b['available'] || $b['analysis'] === null || $b['analysis']['totals']['customers'] === 0) {
            $bundle['windows']['billing'] = $this->window('billing', true, false, null, $b['available'] ? 'No upload lies inside the range, or it holds no customer you may see.' : 'No detailed billing report has been loaded for this region yet.');

            return;
        }

        $range = $b['range'];
        $bundle['windows']['billing'] = $this->window('billing', true, true, [
            'label' => $range['label'], 'from' => $range['from'], 'to' => $range['to'], 'batch_ids' => $range['batch_ids'], 'uploads' => $range['batches'], 'notes' => $b['notes'],
            'sentence' => 'Detailed billing: '.$range['label'].' ('.implode(', ', array_map(fn (array $u) => 'batch #'.$u['id'], $range['batches'])).')',
        ], null);

        $a = $b['analysis'];
        $routes = [];
        $unplaced = [];

        foreach ($a['routes'] as $route) {
            if ($route['route_id'] !== null) {
                $routes[(int) $route['route_id']] = $route;
            } else {
                $unplaced[] = $route;
            }
        }

        $bundle['billing'] = [
            'routes' => $routes, 'unplaced' => $unplaced, 'districts' => $a['districts'], 'totals' => $a['totals'], 'lists' => $b['lists'], 'integrity' => $b['integrity'],
            'cca_source' => $b['cca_source'], 'named' => (bool) $b['named'], 'batch_ids' => $range['batch_ids'], 'range' => $range, 'categories' => $a['categories'],
        ];

        // the CCA beside a billing route is RouteCcaResolver's (carried by the billing report: the one rule)
        $bundle['cca'] = $b['cca_source'];
    }

    /**
     * The billing trend (one point per upload, newest 24) of ONE route: the billing report's own trend, narrowed by its route filter.
     *
     * @return list<array<string, mixed>>
     */
    public function billingTrendOfRoute(int $regionId, int $routeId): array
    {
        $report = $this->billing->build($regionId, null, null, null, $routeId, null, null);

        return $report['available'] && $report['route_id'] === $routeId ? $report['trend'] : [];
    }

    // ---------------------------------------------------------------- the customer-list side

    protected function loadCustomers(array &$bundle, int $regionId, ?int $districtId, ?string $from, ?string $to, bool $typed): void
    {
        $restriction = $this->customerRestriction();
        $districtIds = $this->customerDistrictIds();
        $start = CommercialPeriods::bound($from, false);
        $end = CommercialPeriods::bound($to, true);

        $batches = $this->snapshots->scoped($restriction, $districtIds)->where('region_id', $regionId)->whereNotNull('district_id')
            ->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId))
            ->when($typed && $start !== null, fn ($q) => $q->whereDate('as_of_date', '>=', $start->toDateString()))
            ->when($typed && $end !== null, fn ($q) => $q->whereDate('as_of_date', '<=', $end->toDateString()))
            ->orderBy('as_of_date')->orderBy('id')->get()->keyBy('district_id');

        if ($batches->isEmpty()) {
            $bundle['windows']['customers'] = $this->window('customers', true, false, null, $typed ? 'No customer list is dated inside the range.' : 'No customer list has been loaded for this region yet.');

            return;
        }

        $dates = $batches->map(fn ($b) => Carbon::parse($b->as_of_date)->toDateString())->unique()->sort()->values();
        $label = $dates->count() === 1 ? 'as at '.Carbon::parse($dates->first())->format('j M Y') : 'as at '.Carbon::parse($dates->first())->format('j M Y').' to '.Carbon::parse($dates->last())->format('j M Y');
        $bundle['windows']['customers'] = $this->window('customers', true, true, [
            'label' => $label, 'from' => $dates->first(), 'to' => $dates->last(), 'batch_ids' => $batches->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'sentence' => 'Customer list: '.$label.' ('.$batches->count().' district'.($batches->count() === 1 ? '' : 's').', the newest file of each)',
        ], null);

        $filters = $this->snapshots->filters($batches, $restriction, ['region_id' => $regionId, 'district_id' => $districtId], $districtIds);

        // a code printed under more than one district has aliases pointing at the canonical route: its list accounts may belong to either (flagged, as R8 does)
        $routes = $this->customers->routeBreakdown($filters);

        foreach (array_chunk(array_keys($routes), 500) as $chunk) {
            foreach (CommercialRoute::query()->whereIn('canonical_route_id', $chunk)->distinct()->pluck('canonical_route_id') as $id) {
                $routes[(int) $id]['aliased'] = true;
            }
        }

        $bundle['customers'] = [
            'routes' => $routes, 'districts' => $this->customers->districtBase($filters), 'batch_ids' => $filters->batchIds,
            'as_of' => $dates->last(), 'filters' => $filters,
        ];
    }

    // ---------------------------------------------------------------- what the billing link said

    /**
     * Transitions, the district matrix and the link's own counts, from the NEWEST upload of the billing window (a movement is "since the bill",
     * so it describes one upload, never a sum). Scoped like every billing row: a district user's rows are their district's.
     */
    protected function loadLink(array &$bundle, ?int $districtId): void
    {
        $ids = $bundle['billing']['batch_ids'];
        $newest = CommercialImportBatch::query()->whereIn('id', $ids)->orderByDesc('period_to')->orderByDesc('id')->first();

        if (! $newest) {
            return;
        }

        $link = $newest->control_totals['link'] ?? null;
        $window = ['label' => 'billing of '.CommercialPeriods::describe($newest->period_from, $newest->period_to).' against the list when it was linked', 'from' => $newest->period_from->toDateString(), 'to' => $newest->period_to->toDateString(), 'batch_ids' => [(int) $newest->id]];

        if (! is_array($link) || ! ($link['available'] ?? false) || ! isset($link['by_district'])) {
            $bundle['windows']['link'] = $this->window('link', true, false, null, 'This upload was not linked to the customer list (the list was off or empty when it was loaded, or the upload is older than the comparison). Load the list and upload the report again.');

            return;
        }

        $limited = $this->actorDistrictIds();
        $transitions = $this->scopeDetailRowsForActor(CommercialBillingDetailTransition::query()->where('batch_id', $newest->id))
            ->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId))->toBase()->get()
            ->map(fn ($t) => ['route_id' => (int) $t->route_id, 'district_id' => (int) $t->district_id, 'from_status' => (string) $t->from_status, 'to_status' => (string) $t->to_status, 'from_category' => (string) $t->from_category, 'to_category' => (string) $t->to_category, 'customers' => (int) $t->customers])->all();

        // the matrix: a district user sees the cells that touch their district (as the billing district or as the list's), never the rest
        $matrix = CommercialBillingDetailDistrictCell::query()->where('batch_id', $newest->id)
            ->when($limited !== null, fn ($q) => $limited === [] ? $q->whereRaw('1 = 0') : $q->where(fn ($w) => $w->whereIn('billing_district_id', $limited)->orWhereIn('list_district_id', $limited)))
            ->toBase()->get()->map(fn ($c) => ['billing_district_id' => (int) $c->billing_district_id, 'list_district_id' => (int) $c->list_district_id, 'customers' => (int) $c->customers])->all();

        $byDistrict = array_filter($link['by_district'], fn ($row, $id) => ($limited === null || in_array((int) $id, $limited, true)) && ($districtId === null || (int) $id === $districtId), ARRAY_FILTER_USE_BOTH);
        $listNot = array_filter($link['list_billing_not_in_file_by_district'] ?? [], fn ($n, $id) => ($limited === null || in_array((int) $id, $limited, true)) && ($districtId === null || (int) $id === $districtId), ARRAY_FILTER_USE_BOTH);

        $issues = $this->scopeDetailRowsForActor(CommercialBillingDetailIssue::query()->where('batch_id', $newest->id)->where('kind', CommercialBillingDetailIssue::STATUS_CHANGED))
            ->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId))->orderBy('route_label_raw')->orderBy('account_no')->toBase()->get(['account_no', 'route_id', 'route_label_raw', 'district_id'])
            ->map(fn ($i) => ['account' => (string) $i->account_no, 'route_id' => $i->route_id !== null ? (int) $i->route_id : null, 'route' => (string) $i->route_label_raw, 'district_id' => $i->district_id !== null ? (int) $i->district_id : null])->all();

        $bundle['windows']['link'] = $this->window('link', true, true, $window + ['sentence' => 'Billing against the customer list: the upload of '.CommercialPeriods::describe($newest->period_from, $newest->period_to).' (batch #'.$newest->id.') as linked to the list on '.optional($newest->imported_at)->format('j M Y')], null);

        $bundle['link'] = [
            'batch_id' => (int) $newest->id, 'label' => CommercialPeriods::describe($newest->period_from, $newest->period_to), 'linked_on' => optional($newest->imported_at)->toDateString(),
            'transitions' => $transitions, 'matrix' => $matrix, 'by_district' => $byDistrict, 'by_route' => $link['by_route'] ?? [], 'list_billing_not_in_file' => $listNot, 'status_changed_accounts' => $issues,
            'totals' => $limited === null ? ['accounts' => (int) ($link['accounts'] ?? 0), 'not_in_customer_list' => (int) ($link['not_in_customer_list'] ?? 0), 'list_accounts_not_in_file' => (int) ($link['list_accounts_not_in_file'] ?? 0), 'compared' => (int) ($link['compared'] ?? 0)] : null,
        ];
    }

    /**
     * The statuses of the lookup (code => is_active / is_billing / label), for the screens that colour or group by status.
     *
     * @return array<string, array{active: bool, billing: bool, label: string}>
     */
    public static function statusFlags(): array
    {
        $out = [];

        foreach (DB::table('commercial_customer_statuses')->get(['code', 'label', 'is_active', 'is_billing']) as $s) {
            $out[strtoupper((string) $s->code)] = ['active' => (bool) $s->is_active, 'billing' => (bool) $s->is_billing, 'label' => (string) $s->label];
        }

        return $out;
    }

    /** @return Collection<int, string> */
    public static function districtNames(): Collection
    {
        return District::query()->pluck('district_name', 'id');
    }
}
