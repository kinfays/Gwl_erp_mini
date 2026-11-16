<?php

namespace App\Services\Commercial\Trends;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Models\CommercialCustomerBatch;
use App\Models\CommercialImportBatch;
use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\BillingDetails\BillingDetailsReport;
use App\Services\Commercial\CommercialPeriods;
use App\Services\Commercial\Cross\CrossStats;
use App\Services\Commercial\Cross\GatesParts;
use App\Services\Commercial\Customers\CustomerAnalyticsService;
use App\Services\Commercial\Customers\CustomerSnapshots;
use App\Services\Commercial\PeriodComparison;
use App\Services\Commercial\ReadingRouteReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE CALL behind the Trends screen, its Excel / PDF files and the "change on the previous period" blocks of the single-file screens (Phase 8f,
 * design 8.45): TrendReport::build('t2', $filters). Like CrossFileReport it returns PARTS, each with what it needs (the permission-by-part rule of
 * Phase 8e: a part the viewer may not have is not computed and says what is hidden), and every part names the periods it used.
 *
 * The trends do not re-implement a figure. A period's figures are the single-file report's own (ReadingRouteReport / BillingDetailsReport build() for
 * the dates of an upload, so scope, small-group rule and period model are theirs); the customer list's are its rollups at a snapshot. What a trend adds is
 * WHICH periods are compared (PeriodComparison: the same length within a tolerance, never a 7-day upload against a 31-day one), the change (a ratio in
 * percentage points, a count or an amount absolute and in percent, from sums, never an average of ratios), its tone (the direction of the existing targets)
 * and the ranks.
 *
 *   T2 period on period  T4 rolling windows (7, 30, 90 days)  T5 rank movement  T6 year on year  T7 projection to month end (flows only)  T8 coverage
 *   and timeliness (UploadTimeliness).
 */
class TrendReport
{
    use GatesParts;
    use ScopesCommercialByActor;

    public const TRENDS = [
        't2' => ['code' => 'T2', 'title' => 'Period on period', 'question' => 'How did the headline figures change on the previous comparable period (an upload of about the same length)?'],
        't4' => ['code' => 'T4', 'title' => 'Rolling windows', 'question' => 'What do the trailing 7, 30 and 90 days ending at the latest upload hold?'],
        't5' => ['code' => 'T5', 'title' => 'Rank movement', 'question' => 'Who moved up or down in the ranking since the previous comparable period, and who is new?'],
        't6' => ['code' => 'T6', 'title' => 'Year on year', 'question' => 'How do the headline figures compare with the same dates a year earlier?'],
        't7' => ['code' => 'T7', 'title' => 'Projection to the end of the month', 'question' => 'If the rest of the month is like the days covered so far, where does a flow measure end? (A run rate, not a forecast.)'],
        't8' => ['code' => 'T8', 'title' => 'Coverage and timeliness', 'question' => 'Which days do the uploads cover, how late is each upload, and which districts uploaded last and longest ago?'],
    ];

    public const WINDOWS = [7, 30, 90];

    /** @var array<string, mixed> figures already worked out in this request (a build() of a report is not cheap) */
    protected array $memo = [];

    public function __construct(
        protected ReadingRouteReport $reading,
        protected BillingDetailsReport $billing,
        protected CustomerSnapshots $snapshots,
        protected CustomerAnalyticsService $customers,
        protected PeriodComparison $comparison,
        protected UploadTimeliness $timeliness,
    ) {}

    // ================================================================ the call

    /**
     * @param  array{region?: int|null, district?: int|null, from?: string|null, to?: string|null, route?: int|null, cca?: string|null, month?: string|null, kind?: string|null}  $q
     * @return array<string, mixed>
     */
    public function build(string $key, array $q): array
    {
        $def = self::TRENDS[$key] ?? abort(404);
        $this->memo = [];
        $b = $this->load($q, $key === 't8');
        $parts = [];

        match ($key) {
            't2' => $this->t2($parts, $b, 'previous'),
            't6' => $this->t2($parts, $b, 'last_year'),
            't4' => $this->t4($parts, $b),
            't5' => $this->t5($parts, $b),
            't7' => $this->t7($parts, $b),
            't8' => $this->t8($parts, $b),
        };

        return [
            'key' => $key, 'code' => $def['code'], 'title' => $def['title'], 'question' => $def['question'],
            'region_id' => $b['region_id'], 'region' => $b['region'], 'regions' => $b['regions'], 'districts' => $b['districts'], 'district_id' => $b['district_id'], 'district_limited' => $b['district_limited'],
            'from' => $b['from'], 'to' => $b['to'], 'typed' => $b['typed'], 'month' => $b['month'], 'route_id' => $b['route_id'], 'cca' => $b['cca'], 'kind' => $b['kind'], 'can' => $b['can'], 'named' => $b['can']['named'],
            'windows' => $b['windows'], 'parts' => $parts, 'filters' => ['region' => $b['region_id'], 'district' => $b['district_id'], 'from' => $b['from'], 'to' => $b['to'], 'route' => $b['route_id'], 'cca' => $b['cca'], 'month' => $b['month'], 'kind' => $b['kind']],
            'hidden' => array_values(array_map(fn (array $p) => ['title' => $p['title'], 'reason' => $p['reason']], array_filter($parts, fn (array $p) => ! $p['visible']))),
            // 'any': something is loaded for the parts the viewer may have (the page shows its notes); 'available': at least one part has figures (a file is worth making)
            'notes' => $b['notes'], 'any' => $b['any'], 'available' => $b['any'] && collect($parts)->contains(fn (array $p) => $p['visible'] && ! $p['empty'] && ($p['data']['available'] ?? true) !== false),
        ];
    }

    /**
     * The T2 block of ONE kind for the single-file screens that already show the figure (Reading by route, Billing details, Across the files, the
     * customer-list trends): the same data as the Trends screen's part, so the two cannot disagree. Null when the viewer may not have it.
     *
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>|null
     */
    public function block(string $kind, array $q, string $against = 'previous'): ?array
    {
        $this->memo = [];
        $b = $this->load($q);
        $need = ['reading' => 'reading', 'billing' => 'billing', 'customers' => 'customers'][$kind] ?? null;

        if ($need === null || ! $b['can'][$need] || $b[$need] === null) {
            return null;
        }

        return $this->kindPair($kind, $b, $against);
    }

    /**
     * The route-grain HEADLINE figures of the Summary and Home screens (Phase 8f): reading by route (coverage, skip rate) and the Detailed Billing Report
     * (collection ratio, estimated share, owing balance), each with the period it used and "n of d days are covered". With no range typed each report uses
     * its own newest upload; with a range typed (a whole month or two dates) both use it, by the period model. A part the viewer may not have, or that has no
     * upload, is null; a district user's figures are their own district's. The same builds as the single-file screens, so the figures cannot disagree.
     *
     * @param  array<string, mixed>  $q  region, from, to
     * @return array{region_id: int|null, region: string|null, typed: bool, reading: array<string, mixed>|null, billing: array<string, mixed>|null}
     */
    public function headline(array $q): array
    {
        $this->memo = [];
        $b = $this->load(['region' => $q['region'] ?? null, 'from' => $q['from'] ?? null, 'to' => $q['to'] ?? null]);
        $out = ['region_id' => $b['region_id'], 'region' => $b['region'], 'typed' => $b['typed'], 'reading' => null, 'billing' => null];

        if ($b['region_id'] === null) {
            return $out;
        }

        $targets = ['coverage' => (float) config('gwl.commercial_target_coverage_pct'), 'skip_rate' => (float) config('gwl.commercial_target_skip_rate_pct'), 'collection_ratio' => (float) config('gwl.commercial_target_collection_pct')];
        $wanted = [
            'reading' => ['coverage' => ['Coverage', 'pct', 'up'], 'skip_rate' => ['Skip rate', 'pct', 'down']],
            'billing' => ['collection_ratio' => ['Collection ratio', 'pct', 'up'], 'estimated_share' => ['Estimated bills', 'pct', null], 'owing_amount' => ['Owing balance (GH¢)', 'm', null]],
        ];

        foreach (['reading', 'billing'] as $kind) {
            if (! $b['can'][$kind] || $b[$kind] === null) {
                continue;
            }

            $cur = $this->comparison->current($this->units($this->typeOf($kind), $b['region_id'], $b['district_id']), $b['from'], $b['to']);

            if ($cur === null) {
                continue;
            }

            $from = $b['typed'] ? ($b['from'] ?? $cur['from']) : $cur['from'];
            $to = $b['typed'] ? ($b['to'] ?? $cur['to']) : $cur['to'];
            $fig = $kind === 'reading' ? $this->readingAt($b, $from, $to) : $this->billingAt($b, $from, $to);

            if (! $fig['available']) {
                continue;
            }

            $days = max(1, PeriodComparison::length($from, $to));
            $covered = (int) ($fig['range']['covered_days'] ?? 0);
            $measures = [];

            foreach ($wanted[$kind] as $key => [$label, $unit, $good]) {
                $value = $fig['totals'][$key] ?? null;
                $target = $targets[$key] ?? null;
                $met = $value === null || $target === null || $good === null ? null : ($good === 'up' ? $value >= $target : $value <= $target);
                $measures[] = ['key' => $key, 'label' => $label, 'unit' => $unit, 'value' => $value, 'target' => $target, 'met' => $met];
            }

            $out[$kind] = [
                'title' => $kind === 'reading' ? 'Reading by route and CCA' : 'Detailed billing', 'label' => CommercialPeriods::describe($from, $to), 'from' => $from, 'to' => $to,
                'covered_days' => $covered, 'days' => $days, 'coverage_text' => $covered.' of '.$days.' days are covered', 'held_back' => (bool) ($fig['held_back'] ?? false),
                'batch_ids' => $fig['range']['batch_ids'], 'measures' => $measures,
            ];
        }

        return $out;
    }
    // ================================================================ loading

    /**
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    protected function load(array $q, bool $loose = false): array
    {
        $from = isset($q['from']) && $q['from'] !== '' ? (string) $q['from'] : null;
        $to = isset($q['to']) && $q['to'] !== '' ? (string) $q['to'] : null;
        $can = [
            'reading' => $this->actorCan('commercial.view_reading'),
            'billing' => $this->actorCan('commercial.view_billing'),
            'customers' => $this->actorCan('commercial.view_customer_analytics') && (bool) config('gwl.commercial_customer_list_enabled'),
            'named' => $this->actorCan('commercial.view_reader_performance'),
        ];
        $kind = in_array($q['kind'] ?? 'all', ['all', 'reading', 'billing', 'customers'], true) ? ($q['kind'] ?? 'all') : 'all';

        $b = [
            'regions' => [], 'region_id' => null, 'region' => null, 'districts' => [], 'district_id' => null, 'district_limited' => $this->actorDistrictLimited(), 'from' => $from, 'to' => $to,
            'typed' => CommercialPeriods::bound($from, false) !== null || CommercialPeriods::bound($to, true) !== null, 'month' => null, 'route_id' => null, 'cca' => null, 'kind' => $kind, 'can' => $can,
            'windows' => [], 'reading' => null, 'billing' => null, 'customers' => null, 'link' => null, 'notes' => [], 'any' => false,
        ];

        $titles = ['reading' => 'Reading by route and CCA', 'billing' => 'Detailed billing', 'customers' => 'Customer list'];

        foreach ($titles as $side => $title) {
            $b['windows'][$side] = ['title' => $title, 'reason' => 'No upload has been loaded for this part yet.', 'allowed' => $can[$side], 'available' => false];
        }

        $regions = $this->regionChoices();

        if ($regions === []) {
            return $b;
        }

        $regionId = isset($q['region']) && isset($regions[(int) $q['region']]) ? (int) $q['region'] : (int) array_key_first($regions);
        $b['regions'] = $regions;
        $b['region_id'] = $regionId;
        $b['region'] = $regions[$regionId];
        $b['districts'] = $this->scopeDistrictsForActor(District::query()->where('region_id', $regionId))->orderBy('district_name')->pluck('district_name', 'id')->all();
        $b['district_id'] = isset($q['district']) && isset($b['districts'][(int) $q['district']]) ? (int) $q['district'] : null;
        $b['route_id'] = isset($q['route']) && (int) $q['route'] > 0 ? (int) $q['route'] : null;
        $b['cca'] = $can['named'] && isset($q['cca']) && $q['cca'] !== '' && preg_match('/^[A-Za-z0-9._-]{1,30}$/', (string) $q['cca']) ? (string) $q['cca'] : null;
        $b['month'] = isset($q['month']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $q['month']) ? (string) $q['month'] : null;

        $districtId = $b['district_id'];
        $present = [
            'reading' => $this->units(CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY, $regionId, $districtId) !== [],
            'billing' => $this->units(CommercialImportBatch::TYPE_BILLING_DETAILS, $regionId, $districtId) !== [],
            'customers' => $can['customers'] && $this->snapshots->current($this->customerRestriction(), $regionId, $districtId, null, $this->customerDistrictIds())->isNotEmpty(),
        ];

        if ($loose) {
            // the timeliness page also covers the older reports: a side has data when any of its report types has an upload
            $present['reading'] = $present['reading'] || $this->units(CommercialImportBatch::TYPE_READING_SUMMARY, $regionId, null, false) !== [];
            $present['billing'] = $present['billing'] || $this->units(CommercialImportBatch::TYPE_BILLING_SUMMARY, $regionId, null, false) !== [];
        }

        foreach (['reading', 'billing', 'customers'] as $side) {
            if ($present[$side] && $can[$side]) {
                $b[$side] = true;
                $b['windows'][$side]['available'] = true;
                $b['windows'][$side]['reason'] = null;
            }
        }

        $b['any'] = $b['reading'] !== null || $b['billing'] !== null || $b['customers'] !== null;

        return $b;
    }

    /** @return array<int, string> the regions that have an upload of any report type the viewer may see (id => name), the region of the newest upload first */
    protected function regionChoices(): array
    {
        $newest = [];

        foreach ($this->scopeBatchesForActor(CommercialImportBatch::query())->whereNotNull('region_id')->get(['id', 'region_id', 'status']) as $b) {
            if (in_array($b->status, [CommercialImportBatch::STATUS_IMPORTED, CommercialImportBatch::STATUS_SUPERSEDED], true)) {
                $newest[(int) $b->region_id] = max($newest[(int) $b->region_id] ?? 0, (int) $b->id);
            }
        }

        if (config('gwl.commercial_customer_list_enabled') && $this->customerRestriction() !== 0) {
            foreach ($this->snapshots->scoped($this->customerRestriction(), $this->customerDistrictIds())->whereNotNull('region_id')->distinct()->pluck('region_id') as $id) {
                $newest[(int) $id] ??= 0;
            }
        }

        if ($newest === []) {
            return [];
        }

        $names = Region::query()->whereIn('id', array_keys($newest))->pluck('region_name', 'id')->all();
        uksort($names, fn ($a, $c) => [-($newest[$a] ?? 0), $names[$a]] <=> [-($newest[$c] ?? 0), $names[$c]]);

        return $names;
    }

    /**
     * The live uploads of one report type and region as PeriodComparison units. A district user (or a district filter) is given only the uploads that hold a
     * row of their district: an upload by another district's user says nothing about it.
     *
     * @return list<array{id: int, from: string, to: string, order: int}>
     */
    protected function units(string $type, int $regionId, ?int $districtId, bool $byDistrict = true): array
    {
        $batches = $this->scopeBatchesForActor(CommercialImportBatch::query())->ofType($type)->live()->where('region_id', $regionId)->get();

        if ($batches->isEmpty()) {
            return [];
        }

        $limited = $this->actorDistrictIds();
        $table = ['reading_route_summary' => 'commercial_reading_route_stats', 'billing_details' => 'commercial_billing_detail_rollups'][$type] ?? null;

        if ($byDistrict && $table !== null && ($limited !== null || $districtId !== null)) {
            $query = DB::table($table)->whereIn('batch_id', $batches->pluck('id')->all())->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId));
            $this->commercialScope()->limitDistricts($query, 'district_id');
            $keep = array_flip($query->distinct()->pluck('batch_id')->map(fn ($id) => (int) $id)->all());
            $batches = $batches->filter(fn (CommercialImportBatch $x) => isset($keep[$x->id]));
        }

        return $batches->map(fn (CommercialImportBatch $x) => ['id' => (int) $x->id, 'from' => $x->period_from->toDateString(), 'to' => $x->period_to->toDateString(), 'order' => (int) $x->id])->values()->all();
    }

    // ================================================================ one period's figures (the single-file reports' own)

    /**
     * Reading by route and CCA for a period, normalised for the trends.
     *
     * @return array<string, mixed>
     */
    protected function readingAt(array $b, string $from, string $to): array
    {
        $key = 'r|'.$b['region_id'].'|'.$b['district_id'].'|'.$from.'|'.$to.'|'.$b['route_id'].'|'.$b['cca'];

        return $this->memo[$key] ??= (function () use ($b, $from, $to): array {
            $r = $this->reading->build($b['region_id'], $b['district_id'], $from, $to);
            $range = ['label' => $r['range']['label'] ?? null, 'covered_days' => $r['range']['covered_days'] ?? null, 'range_days' => $r['range']['range_days'] ?? null, 'batch_ids' => $r['range']['batch_ids'] ?? [], 'gaps' => $r['range']['gaps'] ?? []];
            $empty = ['available' => false, 'held_back' => false, 'totals' => [], 'districts' => [], 'ccas' => [], 'routes' => [], 'range' => $range, 'named' => (bool) $r['named'], 'visited' => null, 'read' => null];

            if (! $r['available'] || $r['range']['batch_ids'] === []) {
                return $empty;
            }

            $a = $r['analysis'];

            if ($a === null) {
                return ['available' => true, 'held_back' => true] + $empty;
            }

            $t = $a['totals'];
            $districts = [];

            foreach ($a['districts'] as $d) {
                if ($d['id'] !== null) {
                    $held = (bool) ($d['held_back'] ?? false);
                    $districts[(int) $d['id']] = ['id' => (int) $d['id'], 'label' => (string) $d['name'], 'coverage' => $held ? null : $d['coverage'], 'skip_rate' => $held ? null : $d['skip_rate'], 'unvisited' => $held ? null : $d['unvisited'], 'visited' => $held ? null : $d['visited']];
                }
            }

            $ccas = [];

            foreach ($a['ccas'] as $staff => $c) {
                $ccas[(string) $staff] = ['id' => (string) $staff, 'label' => trim($staff.' '.$c['name']), 'coverage' => $c['coverage'], 'skip_rate' => $c['skip_rate'], 'unvisited' => $c['unvisited'], 'visited' => $c['visited'], 'rank' => $c['rank'], 'eligible' => (bool) $c['eligible']];
            }

            $routes = [];

            foreach ($a['routes'] as $route) {
                if ($route['placed'] && $route['route_id'] !== null) {
                    $routes[(int) $route['route_id']] = ['id' => (int) $route['route_id'], 'label' => (string) $route['label'], 'district' => $route['district'], 'coverage' => $route['coverage'], 'skip_rate' => $route['skip_rate'], 'unvisited' => $route['unvisited'], 'visited' => $route['visited'], 'owner' => $route['owner_cca']];
                }
            }

            $totals = ['coverage' => $t['coverage'], 'skip_rate' => $t['skip_rate'], 'unvisited' => $t['unvisited'], 'visited' => $t['visited']];

            // a route or a CCA filter narrows the headline to that route's or CCA's own row
            if ($b['route_id'] !== null) {
                $row = $routes[$b['route_id']] ?? null;
                $totals = $row ? ['coverage' => $row['coverage'], 'skip_rate' => $row['skip_rate'], 'unvisited' => $row['unvisited'], 'visited' => $row['visited']] : ['coverage' => null, 'skip_rate' => null, 'unvisited' => null, 'visited' => null];
            } elseif ($b['cca'] !== null) {
                $row = $ccas[$b['cca']] ?? null;
                $totals = $row ? ['coverage' => $row['coverage'], 'skip_rate' => $row['skip_rate'], 'unvisited' => $row['unvisited'], 'visited' => $row['visited']] : ['coverage' => null, 'skip_rate' => null, 'unvisited' => null, 'visited' => null];
            }

            return ['available' => true, 'held_back' => false, 'totals' => $totals, 'districts' => $districts, 'ccas' => $ccas, 'routes' => $routes, 'range' => $range, 'named' => (bool) $r['named'], 'min_visits' => $a['min_visits'] ?? null];
        })();
    }

    /**
     * Detailed billing for a period, normalised for the trends.
     *
     * @return array<string, mixed>
     */
    protected function billingAt(array $b, string $from, string $to): array
    {
        $key = 'b|'.$b['region_id'].'|'.$b['district_id'].'|'.$from.'|'.$to.'|'.$b['route_id'].'|'.$b['cca'];

        return $this->memo[$key] ??= (function () use ($b, $from, $to): array {
            $r = $this->billing->build($b['region_id'], $b['district_id'], null, $b['cca'], $b['route_id'], $from, $to);
            $range = ['label' => $r['range']['label'] ?? null, 'covered_days' => $r['range']['covered_days'] ?? null, 'range_days' => $r['range']['range_days'] ?? null, 'batch_ids' => $r['range']['batch_ids'] ?? [], 'gaps' => $r['range']['gaps'] ?? []];
            $empty = ['available' => false, 'totals' => [], 'districts' => [], 'routes' => [], 'range' => $range, 'named' => (bool) $r['named']];

            if (! $r['available'] || $r['analysis'] === null || $r['range']['batch_ids'] === [] || $r['analysis']['totals']['customers'] === 0) {
                return $empty;
            }

            $a = $r['analysis'];
            $row = fn (array $m) => ['coverage' => null, 'estimated_share' => $m['estimated_share'], 'unbilled_share' => $m['unbilled_share'], 'collection_ratio' => $m['collection_ratio'], 'owing_amount' => $m['owing_amount'], 'month_total' => $m['month_total'], 'paid' => $m['paid']];
            $districts = [];

            foreach ($a['districts'] as $d) {
                if ($d['id'] !== null) {
                    $districts[(int) $d['id']] = ['id' => (int) $d['id'], 'label' => (string) $d['name']] + $row($d);
                }
            }

            $routes = [];

            foreach ($a['routes'] as $route) {
                if ($route['route_id'] !== null) {
                    $routes[(int) $route['route_id']] = ['id' => (int) $route['route_id'], 'label' => (string) $route['label'], 'district' => $route['district']] + $row($route);
                }
            }

            return ['available' => true, 'totals' => $row($a['totals']), 'districts' => $districts, 'routes' => $routes, 'range' => $range, 'named' => (bool) $r['named']];
        })();
    }

    /** @return array{type: string} */
    protected function typeOf(string $kind): string
    {
        return $kind === 'reading' ? CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY : CommercialImportBatch::TYPE_BILLING_DETAILS;
    }

    /**
     * The current period of a kind and the one it is compared with (PeriodComparison), with the figures of both.
     *
     * @return array<string, mixed>
     */
    protected function pairOf(string $kind, array $b, string $against): array
    {
        $units = $this->units($this->typeOf($kind), $b['region_id'], $b['district_id']);
        $res = $this->comparison->resolve($units, $b['from'], $b['to']);
        $cur = $res['current'];

        if ($cur === null) {
            return ['available' => false, 'note' => 'No upload lies inside the range.', 'current' => null, 'compared' => null, 'now' => null, 'then' => null];
        }

        $then = $against === 'last_year' ? $res['last_year'] : $res['previous'];
        $note = $against === 'last_year' ? $res['last_year_note'] : $res['previous_note'];
        $at = $kind === 'reading' ? fn (string $f, string $t) => $this->readingAt($b, $f, $t) : fn (string $f, string $t) => $this->billingAt($b, $f, $t);
        $now = $cur['typed'] ? $at($b['from'] ?? $cur['from'], $b['to'] ?? $cur['to']) : $at($cur['from'], $cur['to']);

        return [
            'available' => true, 'note' => $note, 'now' => $now, 'then' => $then ? $at($then['from'], $then['to']) : null,
            'current' => ['from' => $cur['from'], 'to' => $cur['to'], 'length' => $cur['length'], 'label' => CommercialPeriods::describe($cur['from'], $cur['to']), 'batch_ids' => $cur['batch_ids'], 'covered_days' => $cur['covered_days'], 'range_days' => $cur['range_days']],
            'compared' => $then ? ['from' => $then['from'], 'to' => $then['to'], 'length' => PeriodComparison::length($then['from'], $then['to']), 'label' => CommercialPeriods::describe($then['from'], $then['to']), 'batch_ids' => [$then['id']]] : null,
            'tolerance' => $this->comparison->tolerance(),
        ];
    }

    // ================================================================ T2 and T6

    protected function t2(array &$parts, array $b, string $against): void
    {
        $kinds = $b['kind'] === 'all' ? ['reading', 'billing', 'customers'] : [$b['kind']];

        foreach (['reading' => 'Reading by route and CCA', 'billing' => 'Detailed billing', 'customers' => 'Customer list'] as $kind => $title) {
            if (in_array($kind, $kinds, true)) {
                $this->part($parts, $b, $kind, $title, [$kind], fn () => $this->kindPair($kind, $b, $against));
            }
        }
    }

    /**
     * T2 / T6 of one kind: the headline measures, and the same by district (and by CCA and route for the route-grain kinds).
     *
     * @return array<string, mixed>
     */
    protected function kindPair(string $kind, array $b, string $against): array
    {
        if ($kind === 'customers') {
            return $this->customersPair($b, $against);
        }

        $p = $this->pairOf($kind, $b, $against);

        if (! $p['available']) {
            return ['kind' => $kind, 'against' => $against, 'available' => false, 'note' => $p['note']] + ['current' => null, 'compared' => null, 'measures' => [], 'districts' => [], 'ccas' => [], 'routes' => []];
        }

        $now = $p['now'];
        $then = $p['then'];
        $measures = fn (array $a, ?array $c) => TrendFigures::measures($kind, $a, $c);
        $rows = function (string $set) use ($now, $then, $measures): array {
            $out = [];

            foreach (array_unique(array_merge(array_keys($now[$set] ?? []), array_keys($then[$set] ?? []))) as $id) {
                $n = $now[$set][$id] ?? null;
                $t = $then[$set][$id] ?? null;
                $out[] = ['id' => $id, 'label' => (string) (($n ?? $t)['label']), 'measures' => $measures($n ?? [], $then === null ? null : ($t ?? []))];
            }

            usort($out, fn (array $x, array $y) => strcmp($x['label'], $y['label']));

            return $out;
        };

        return [
            'kind' => $kind, 'against' => $against, 'available' => $now['available'], 'held_back' => (bool) ($now['held_back'] ?? false), 'note' => $p['note'], 'current' => $p['current'] + ['range' => $now['range']], 'compared' => $p['compared'],
            'measures' => $now['available'] ? $measures($now['totals'], $then === null ? null : ($then['available'] ? $then['totals'] : [])) : [],
            'districts' => $rows('districts'), 'ccas' => $kind === 'reading' && $b['can']['named'] ? $rows('ccas') : [], 'routes' => $rows('routes'),
        ];
    }

    /**
     * T2 / T6 of the customer list: billing accounts and the contactable share, district by district, between the two newest snapshots (T2) or against the
     * snapshot of the same date a year earlier (T6). The regional line counts only the districts that have both snapshots.
     *
     * @return array<string, mixed>
     */
    protected function customersPair(array $b, string $against): array
    {
        $restriction = $this->customerRestriction();
        $districtIds = $this->customerDistrictIds();
        $current = $this->snapshots->current($restriction, $b['region_id'], $b['district_id'], null, $districtIds);

        if ($current->isEmpty()) {
            return ['kind' => 'customers', 'against' => $against, 'available' => false, 'note' => 'No customer list has been loaded for this region yet.', 'current' => null, 'compared' => null, 'measures' => [], 'districts' => []];
        }

        $before = $against === 'last_year' ? $this->lastYearSnapshots($restriction, $current, $districtIds) : $this->snapshots->previous($restriction, $current, $districtIds);
        $base = function ($batches) use ($restriction, $districtIds, $b): array {
            return $batches->isEmpty() ? [] : $this->customers->districtBase($this->snapshots->filters($batches, $restriction, ['region_id' => $b['region_id']], $districtIds));
        };
        $nowBase = $base($current);
        $thenBase = $base($before);
        $names = District::query()->pluck('district_name', 'id');
        $rows = [];
        $sum = ['now_accounts' => 0, 'then_accounts' => 0, 'now_contact' => 0, 'then_contact' => 0, 'now_customers' => 0, 'then_customers' => 0, 'complete' => true, 'districts' => 0];

        foreach ($current as $districtId => $batch) {
            $n = $nowBase[$districtId] ?? null;
            $prior = $before[$districtId] ?? null;
            $t = $thenBase[$districtId] ?? null;
            $rows[] = [
                'id' => (int) $districtId, 'label' => (string) ($names[$districtId] ?? 'District '.$districtId), 'now_label' => UploadTimeliness::snapshotLabel($batch), 'then_label' => $prior ? UploadTimeliness::snapshotLabel($prior) : null,
                'days_apart' => $prior ? (int) Carbon::parse($prior->as_of_date)->diffInDays(Carbon::parse($batch->as_of_date)) : null,
                'measures' => TrendFigures::measures('customers', ['billing_accounts' => $n['billing'] ?? null, 'contactable_share' => $n['contactable_share'] ?? null], $prior ? ['billing_accounts' => $t['billing'] ?? null, 'contactable_share' => $t['contactable_share'] ?? null] : null),
            ];

            if ($prior && $n && $t) {
                $sum['districts']++;
                $sum['now_accounts'] += $n['billing'];
                $sum['then_accounts'] += $t['billing'];
                $sum['now_customers'] += $n['customers'];
                $sum['then_customers'] += $t['customers'];

                if ($n['contactable'] === null || $t['contactable'] === null) {
                    $sum['complete'] = false;
                } else {
                    $sum['now_contact'] += $n['contactable'];
                    $sum['then_contact'] += $t['contactable'];
                }
            }
        }

        usort($rows, fn (array $x, array $y) => strcmp($x['label'], $y['label']));

        $measures = [];

        if ($sum['districts'] > 0 && ! $this->actorDistrictLimited()) {
            $measures = TrendFigures::measures('customers', [
                'billing_accounts' => $sum['now_accounts'], 'contactable_share' => $sum['complete'] ? CrossStats::pct($sum['now_contact'], $sum['now_customers']) : null,
            ], [
                'billing_accounts' => $sum['then_accounts'], 'contactable_share' => $sum['complete'] ? CrossStats::pct($sum['then_contact'], $sum['then_customers']) : null,
            ]);
        }

        $dates = fn ($set) => $set->map(fn ($x) => Carbon::parse($x->as_of_date)->toDateString())->unique()->sort()->values();

        return [
            'kind' => 'customers', 'against' => $against, 'available' => true, 'held_back' => false,
            'note' => $before->isEmpty() ? ($against === 'last_year' ? 'No customer list is dated about a year before.' : 'There is only one customer-list snapshot: nothing to compare it with.') : null,
            'current' => ['label' => 'as at '.$dates($current)->map(fn ($d) => Carbon::parse($d)->format('j M Y'))->implode(', '), 'from' => $dates($current)->first(), 'to' => $dates($current)->last(), 'batch_ids' => $current->pluck('id')->map(fn ($id) => (int) $id)->values()->all()],
            'compared' => $before->isEmpty() ? null : ['label' => 'as at '.$dates($before)->map(fn ($d) => Carbon::parse($d)->format('j M Y'))->implode(', '), 'from' => $dates($before)->first(), 'to' => $dates($before)->last(), 'batch_ids' => $before->pluck('id')->map(fn ($id) => (int) $id)->values()->all()],
            'measures' => $measures, 'districts' => $rows, 'districts_compared' => $sum['districts'],
        ];
    }

    /**
     * Per district, the snapshot dated closest to the same date a year before the current one (within the length tolerance).
     *
     * @return Collection<int, CommercialCustomerBatch>
     */
    protected function lastYearSnapshots(?int $restriction, $current, ?array $districtIds)
    {
        $out = collect();
        $tolerance = $this->comparison->tolerance();

        foreach ($current as $districtId => $batch) {
            $target = Carbon::parse($batch->as_of_date)->startOfDay()->subYear();
            $found = $this->snapshots->scoped($restriction, $districtIds)->where('district_id', $districtId)
                ->whereDate('as_of_date', '>=', $target->copy()->subDays($tolerance)->toDateString())->whereDate('as_of_date', '<=', $target->copy()->addDays($tolerance)->toDateString())->get()
                ->sortBy(fn ($x) => [abs($target->diffInDays(Carbon::parse($x->as_of_date)->startOfDay(), false)), -$x->id])->first();

            if ($found) {
                $out->put($districtId, $found);
            }
        }

        return $out;
    }

    // ================================================================ T4: rolling windows

    protected function t4(array &$parts, array $b): void
    {
        $this->part($parts, $b, 'reading', 'Reading by route and CCA', ['reading'], fn () => $this->rolling('reading', $b));
        $this->part($parts, $b, 'billing', 'Detailed billing', ['billing'], fn () => $this->rolling('billing', $b));
    }

    /**
     * The trailing 7, 30 and 90 days ending at the latest upload's last day (inside the typed range, if one is typed), each worked out by the single-file
     * report's period model: uploads that lie wholly inside the window and do not overlap add up; how many of the window's days they cover is stated.
     *
     * @return array<string, mixed>
     */
    protected function rolling(string $kind, array $b): array
    {
        $units = $this->units($this->typeOf($kind), $b['region_id'], $b['district_id']);
        $cur = $this->comparison->current($units, $b['from'], $b['to']);

        if ($cur === null) {
            return ['kind' => $kind, 'available' => false, 'note' => 'No upload lies inside the range.', 'end' => null, 'windows' => []];
        }

        $end = Carbon::parse($cur['to'])->startOfDay();
        $windows = [];

        foreach (self::WINDOWS as $days) {
            $from = $end->copy()->subDays($days - 1)->toDateString();
            $fig = $kind === 'reading' ? $this->readingAt($b, $from, $end->toDateString()) : $this->billingAt($b, $from, $end->toDateString());
            $covered = (int) ($fig['range']['covered_days'] ?? 0);
            $windows[] = [
                'days' => $days, 'from' => $from, 'to' => $end->toDateString(), 'label' => CommercialPeriods::describe($from, $end), 'available' => $fig['available'], 'held_back' => (bool) ($fig['held_back'] ?? false),
                'covered_days' => $covered, 'coverage_text' => $fig['available'] ? $covered.' of '.$days.' days are covered' : 'no upload lies inside this window', 'batch_ids' => $fig['range']['batch_ids'],
                'figures' => $fig['available'] ? $fig['totals'] : [],
            ];
        }

        return ['kind' => $kind, 'available' => true, 'note' => null, 'end' => $end->toDateString(), 'end_label' => $end->format('j M Y'), 'windows' => $windows, 'measures' => array_merge(array_keys(TrendFigures::MEASURES[$kind]), $kind === 'reading' ? ['visited'] : ['paid'])];
    }

    // ================================================================ T5: rank movement

    protected function t5(array &$parts, array $b): void
    {
        $this->part($parts, $b, 'ccas', 'CCAs by skip rate', ['reading', 'named'], fn () => $this->rankSet($b, 'reading', 'ccas', 'skip_rate', true, 'CCA'));
        $this->part($parts, $b, 'routes_skip', 'Routes by skip rate', ['reading'], fn () => $this->rankSet($b, 'reading', 'routes', 'skip_rate', true, 'Route'));
        $this->part($parts, $b, 'districts_skip', 'Districts by skip rate', ['reading'], fn () => $this->rankSet($b, 'reading', 'districts', 'skip_rate', true, 'District'));
        $this->part($parts, $b, 'routes_collection', 'Routes by collection ratio', ['billing'], fn () => $this->rankSet($b, 'billing', 'routes', 'collection_ratio', false, 'Route'));
        $this->part($parts, $b, 'districts_collection', 'Districts by collection ratio', ['billing'], fn () => $this->rankSet($b, 'billing', 'districts', 'collection_ratio', false, 'District'));
    }

    /**
     * Rank now (in the current period) and in the previous comparable period, and the move. Only items with a value are ranked: a CCA needs at least
     * commercial_min_visits_for_ranking visits (the reading report's own rule), a route needs a skip rate (visits) or a collection ratio (billed).
     *
     * @return array<string, mixed>
     */
    protected function rankSet(array $b, string $kind, string $set, string $measure, bool $ascending, string $noun): array
    {
        $p = $this->pairOf($kind, $b, 'previous');

        if (! $p['available'] || ! $p['now']['available']) {
            return ['available' => false, 'note' => $p['note'] ?? 'No upload lies inside the range.', 'rows' => []];
        }

        $items = function (?array $fig) use ($set, $measure): array {
            $out = [];

            foreach ($fig[$set] ?? [] as $id => $row) {
                // a CCA below the minimum of visits is not ranked (the reading report's own eligibility)
                $value = $set === 'ccas' && ! ($row['eligible'] ?? false) ? null : $row[$measure];
                $out[$id] = ['value' => $value, 'label' => (string) $row['label'], 'raw' => $row[$measure]];
            }

            return $out;
        };

        $now = $items($p['now']);
        $then = $p['then'] && $p['then']['available'] ? $items($p['then']) : [];
        $rankNow = TrendFigures::ranks($now, $ascending);
        $rankThen = TrendFigures::ranks($then, $ascending);
        $rows = [];

        foreach (array_unique(array_merge(array_keys($now), array_keys($then))) as $id) {
            $move = TrendFigures::move($rankNow[$id] ?? null, $rankThen[$id] ?? null);
            $label = (string) (($now[$id] ?? $then[$id])['label']);
            $rows[] = ['id' => $id, 'label' => $label, 'value_now' => $now[$id]['raw'] ?? null, 'value_then' => $then[$id]['raw'] ?? null, 'rank_now' => $rankNow[$id] ?? null, 'rank_then' => $rankThen[$id] ?? null] + $move;
        }

        usort($rows, fn (array $x, array $y) => [$x['rank_now'] ?? PHP_INT_MAX, $x['label']] <=> [$y['rank_now'] ?? PHP_INT_MAX, $y['label']]);

        return [
            'available' => true, 'noun' => $noun, 'measure' => $measure, 'ascending' => $ascending, 'rows' => $rows, 'note' => $p['compared'] ? null : $p['note'],
            'current' => $p['current'], 'compared' => $p['compared'], 'ranked_now' => count($rankNow), 'ranked_then' => count($rankThen),
        ];
    }

    // ================================================================ T7: projection to the end of the month

    protected function t7(array &$parts, array $b): void
    {
        $this->part($parts, $b, 'reading', 'Reading by route and CCA', ['reading'], fn () => $this->projection('reading', $b, ['visited' => 'Visited']));
        $this->part($parts, $b, 'billing', 'Detailed billing', ['billing'], fn () => $this->projection('billing', $b, ['month_total' => 'Month total (GH¢)', 'paid' => 'Paid this month (GH¢)']));
    }

    /**
     * The month asked for (the month of the latest upload's last day by default), what the days covered by uploads hold, and the run rate to the end of the
     * month for each FLOW measure. Never for a stock (a balance, a strength): the page says so.
     *
     * @param  array<string, string>  $flows  measure => label
     * @return array<string, mixed>
     */
    protected function projection(string $kind, array $b, array $flows): array
    {
        $units = $this->units($this->typeOf($kind), $b['region_id'], $b['district_id']);
        $month = $b['month'];

        if ($month === null) {
            $cur = $this->comparison->current($units, $b['from'], $b['to']);
            $month = $cur ? substr($cur['to'], 0, 7) : null;
        }

        if ($month === null) {
            return ['kind' => $kind, 'available' => false, 'note' => 'No upload lies inside the range.', 'measures' => []];
        }

        $first = Carbon::parse($month.'-01')->startOfMonth();
        $last = $first->copy()->endOfMonth();
        $fig = $kind === 'reading' ? $this->readingAt($b, $first->toDateString(), $last->toDateString()) : $this->billingAt($b, $first->toDateString(), $last->toDateString());

        if (! $fig['available']) {
            return ['kind' => $kind, 'available' => false, 'note' => 'No upload lies wholly inside '.$first->format('F Y').'.', 'month' => $first->format('Y-m'), 'month_label' => $first->format('F Y'), 'measures' => []];
        }

        $covered = (int) $fig['range']['covered_days'];
        $min = max(1, (int) config('gwl.commercial_projection_min_days', 7));
        $measures = [];

        foreach ($flows as $key => $label) {
            $actual = (float) ($fig['totals'][$key] ?? 0);
            $measures[] = ['key' => $key, 'label' => $label, 'actual' => $actual, 'target' => null] + TrendFigures::projection($actual, $covered, $last->day, $min);
        }

        return [
            'kind' => $kind, 'available' => true, 'note' => null, 'month' => $first->format('Y-m'), 'month_label' => $first->format('F Y'), 'days_in_month' => $last->day, 'covered_days' => $covered, 'min_days' => $min,
            'coverage_text' => $covered.' of '.$last->day.' days are covered', 'batch_ids' => $fig['range']['batch_ids'], 'measures' => $measures,
            'label' => 'projection, not a forecast',
        ];
    }

    // ================================================================ T8: coverage and timeliness

    protected function t8(array &$parts, array &$b): void
    {
        $month = $b['month'] ?? $this->latestMonth($b) ?? now()->format('Y-m');
        $b['month'] = $month;

        foreach (UploadTimeliness::TYPES as $key => $def) {
            $this->part($parts, $b, $key, $def['label'], [$def['needs']], fn () => $b['region_id'] === null ? ['available' => false, 'note' => 'Nothing has been loaded.'] : $this->timeliness->build($key, $b['region_id'], $month, $b['district_id']));
        }
    }

    /** The month of the newest period end among the live uploads of any report type (the default month of the calendar). */
    protected function latestMonth(array $b): ?string
    {
        if ($b['region_id'] === null) {
            return null;
        }

        $latest = $this->scopeBatchesForActor(CommercialImportBatch::query())->live()->where('region_id', $b['region_id'])->max('period_to');
        $customer = config('gwl.commercial_customer_list_enabled') ? $this->snapshots->scoped($this->customerRestriction(), $this->customerDistrictIds())->where('region_id', $b['region_id'])->max('as_of_date') : null;
        $newest = collect([$latest, $customer])->filter()->map(fn ($d) => Carbon::parse($d)->toDateString())->max();

        return $newest ? substr($newest, 0, 7) : null;
    }
}
