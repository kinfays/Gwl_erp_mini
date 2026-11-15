<?php

namespace App\Services\Commercial\Cross;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Models\CommercialBillingRoute;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingRouteStat;
use App\Models\CommercialRoute;
use App\Models\District;
use App\Services\Commercial\CommercialPeriods;
use App\Services\Commercial\Customers\CustomerListService;
use App\Services\Commercial\RouteResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE CALL behind every "Across the files" screen and every export of one (Phase 8e, design 8.42): CrossFileReport::build('x1', $filters).
 * It loads what the analysis needs (CrossFileSources: the window rule, the permission rule, the scope), hands it to the pure passes
 * (CrossFileAnalytics) and returns a payload of PARTS. A part is one block of the page: it says what it needs, whether the viewer may have it,
 * and carries its data; a part the viewer may not have is not computed at all and says what is hidden (the other parts are still shown).
 *
 * Every payload names the period each side used (`windows`), so no figure can be read without knowing which file and which dates it comes from.
 */
class CrossFileReport
{
    use ScopesCommercialByActor;

    /**
     * key => title, what it answers, and the sides to load. `needs` of a part are checked per part (see part()).
     */
    public const ANALYSES = [
        'x1' => ['code' => 'X1', 'title' => 'CCA performance against billing outcomes', 'sides' => ['reading', 'billing'], 'question' => 'For the routes each CCA owns, how do the visits and skips compare with the bills on those routes: estimated, unbilled, collected, owing?'],
        'x3' => ['code' => 'X3', 'title' => 'Skip against estimation, route by route', 'sides' => ['reading', 'billing'], 'question' => 'Do the routes that are skipped more often have more bills estimated on a working meter? (A correlation, not a cause.)'],
        'x4' => ['code' => 'X4', 'title' => 'Strength reconciliation', 'sides' => ['reading', 'billing', 'customers'], 'question' => 'Does a route\'s verified strength agree with its metered accounts in the customer list and with its customers in billing?'],
        'x5' => ['code' => 'X5', 'title' => 'Billing against the customer list', 'sides' => ['billing', 'customers', 'link'], 'question' => 'Which accounts are in one file and not the other, and which changed status or service category between the bill and the list?'],
        'x6' => ['code' => 'X6', 'title' => 'Status exceptions with their CCA and route', 'sides' => ['billing', 'customers', 'link'], 'question' => 'Where are active accounts unbilled, disconnected accounts billed, accounts on HOLD, and accounts billed as active that are now disconnected?'],
        'x7' => ['code' => 'X7', 'title' => 'District scorecard from the real routes', 'sides' => ['reading', 'billing', 'customers'], 'question' => 'Per district: reading, billing and customer-list figures side by side, against the targets in Settings.'],
        'x8' => ['code' => 'X8', 'title' => 'Arrears per CCA portfolio', 'sides' => ['billing'], 'question' => 'How much is owed on the routes each CCA owns, and which routes hold most of it?'],
        'x9' => ['code' => 'X9', 'title' => 'Read recency against visits', 'sides' => ['reading', 'billing'], 'question' => 'Are the routes whose last reads are old the routes (and CCAs) with low coverage?'],
        'x10' => ['code' => 'X10', 'title' => 'District and route evidence', 'sides' => ['reading', 'billing', 'customers', 'link'], 'question' => 'Which system owns a route\'s district? Where do the billing and the customer list disagree?'],
        'x11' => ['code' => 'X11', 'title' => 'Executive summary by district', 'sides' => ['reading', 'billing', 'customers'], 'question' => 'One page: each district\'s headline figures and what needs attention.'],
        'x12' => ['code' => 'X12', 'title' => 'Detailed billing against the billing summary', 'sides' => ['billing'], 'question' => 'Do the Detailed Billing Report and the older billing summary agree on customers billed, billing, payments and the closing balance?'],
    ];

    public function __construct(protected CrossFileSources $sources, protected CrossFileAnalytics $analytics) {}

    /**
     * @param  array{region?: int|null, district?: int|null, from?: string|null, to?: string|null, mode?: string|null}  $q
     * @return array<string, mixed>
     */
    public function build(string $key, array $q): array
    {
        $def = self::ANALYSES[$key] ?? abort(404);
        $b = $this->sources->load($q, $def['sides']);
        $parts = [];

        match ($key) {
            'x1' => $this->x1($parts, $b),
            'x3' => $this->x3($parts, $b),
            'x4' => $this->x4($parts, $b),
            'x5' => $this->x5($parts, $b),
            'x6' => $this->x6($parts, $b),
            'x7' => $this->x7($parts, $b),
            'x8' => $this->x8($parts, $b),
            'x9' => $this->x9($parts, $b),
            'x10' => $this->x10($parts, $b),
            'x11' => $this->x11($parts, $b),
            'x12' => $this->x12($parts, $b),
        };

        return [
            'key' => $key, 'code' => $def['code'], 'title' => $def['title'], 'question' => $def['question'],
            'filters' => ['region' => $b['region_id'], 'district' => $b['district_id'], 'from' => $b['from'], 'to' => $b['to'], 'mode' => $b['mode']],
            'region_id' => $b['region_id'], 'region' => $b['region'], 'regions' => $b['regions'], 'districts' => $b['districts'], 'district_id' => $b['district_id'], 'district_limited' => $b['district_limited'],
            'mode' => $b['mode'], 'typed' => $b['typed'], 'from' => $b['from'], 'to' => $b['to'], 'can' => $b['can'], 'windows' => $this->windowsFor($b, $def['sides']), 'parts' => $parts,
            'hidden' => array_values(array_map(fn (array $p) => ['title' => $p['title'], 'reason' => $p['reason']], array_filter($parts, fn (array $p) => ! $p['visible']))),
            'notes' => $this->notes($b, $def['sides']), 'available' => $b['any'] && collect($parts)->contains(fn (array $p) => $p['visible'] && ! $p['empty']),
            'named' => $b['can']['named'],
        ];
    }

    // ---------------------------------------------------------------- parts and what is hidden

    /**
     * @param  array<string, array<string, mixed>>  $parts
     * @param  array<string, mixed>  $b
     * @param  list<string>  $needs  reading, billing, customers, link, named
     */
    protected function part(array &$parts, array $b, string $key, string $title, array $needs, callable $make): void
    {
        $missing = $this->missing($needs, $b);

        if ($missing !== []) {
            $parts[$key] = ['key' => $key, 'title' => $title, 'visible' => false, 'empty' => true, 'reason' => 'Hidden: it needs '.implode(' and ', $missing).'.', 'data' => null];

            return;
        }

        $absent = $this->absent($needs, $b);

        if ($absent !== []) {
            $parts[$key] = ['key' => $key, 'title' => $title, 'visible' => true, 'empty' => true, 'reason' => implode(' ', $absent), 'data' => null];

            return;
        }

        $parts[$key] = ['key' => $key, 'title' => $title, 'visible' => true, 'empty' => false, 'reason' => null, 'data' => $make()];
    }

    /** @return list<string> what the viewer lacks for these needs */
    protected function missing(array $needs, array $b): array
    {
        $out = [];

        foreach ($needs as $need) {
            $ok = match ($need) {
                'link' => $b['can']['billing'] && $b['can']['customers'],
                'named' => $b['can']['named'],
                default => $b['can'][$need] ?? false,
            };

            if (! $ok) {
                $out[] = match ($need) {
                    'reading' => 'the reading permission (commercial.view_reading)',
                    'billing' => 'the billing permission (commercial.view_billing)',
                    'customers' => config('gwl.commercial_customer_list_enabled') ? 'the customer-list analytics permission (commercial.view_customer_analytics)' : 'the customer list, which is switched off',
                    'link' => 'both the billing and the customer-list permissions',
                    'named' => 'the CCA performance permission (commercial.view_reader_performance)',
                    default => $need,
                };
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> the sides the viewer may have that hold nothing for the window */
    protected function absent(array $needs, array $b): array
    {
        $out = [];

        foreach ($needs as $need) {
            if ($need === 'named') {
                continue;
            }

            $w = $b['windows'][$need] ?? null;
            $has = $need === 'link' ? $b['link'] !== null : ($b[$need] ?? null) !== null;

            if (! $has) {
                $out[] = ($w['title'] ?? $need).': '.($w['reason'] ?? 'nothing has been loaded.');
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $sides
     * @return array<string, array<string, mixed>>
     */
    protected function windowsFor(array $b, array $sides): array
    {
        return array_intersect_key($b['windows'], array_flip($sides));
    }

    /**
     * What to say about the window rule and the held-back figures, on every page and every file.
     *
     * @param  list<string>  $sides
     * @return list<string>
     */
    protected function notes(array $b, array $sides): array
    {
        $notes = [];

        $notes[] = $b['mode'] === 'same' && $b['typed']
            ? 'Every part uses the range you typed (the uploads inside it that do not overlap are added up, as on each part\'s own screen).'
            : ($b['typed'] ? 'Each part uses its own newest upload inside the range you typed.' : 'Each part uses its own newest upload: the files are NOT for the same days, so read each figure with the period named beside it.');

        if (($b['reading']['held_back'] ?? []) !== []) {
            $notes[] = 'Reading figures of '.implode(', ', $b['reading']['held_back']).' are held back: too few CCAs to show without naming them.';
        }

        if ($b['district_limited']) {
            $notes[] = 'You see your own district only. The customer list is limited to the accounts it puts in your district, which can differ from the district billing and reading put a route in (see X10).';
        }

        return $notes;
    }

    // ---------------------------------------------------------------- X1

    protected function x1(array &$parts, array $b): void
    {
        $this->part($parts, $b, 'combined', 'Reading beside billing, per CCA (the routes it owns in the reading upload)', ['reading', 'billing', 'named'], fn () => $this->analytics->x1($b['reading'], $b['billing']));
        $this->part($parts, $b, 'reading', 'Reading figures per CCA', ['reading', 'named'], fn () => $this->analytics->x1($b['reading'], null));
        $this->part($parts, $b, 'billing', 'Billing figures per CCA (the routes it owns for the bill period)', ['billing', 'named'], fn () => ['rows' => $this->analytics->billingByCca($b['billing'])]);
    }

    // ---------------------------------------------------------------- X3

    protected function x3(array &$parts, array $b): void
    {
        $min = max(1, (int) config('gwl.commercial_cross_min_route_customers', 30));
        $this->part($parts, $b, 'correlation', 'The correlation, route by route', ['reading', 'billing'], fn () => $this->analytics->x3($b['reading'], $b['billing'], $min));
        $this->part($parts, $b, 'skip', 'Skip rate by route (reading alone)', ['reading'], fn () => ['rows' => collect($b['reading']['routes'])->filter(fn (array $r) => $r['skip_rate'] !== null)->sortByDesc('skip_rate')->values()->map(fn (array $r) => ['label' => $r['label'], 'district' => $r['district'], 'visited' => $r['visited'], 'skip_rate' => $r['skip_rate']])->all()]);
        $this->part($parts, $b, 'estimates', 'Bills estimated on a working meter by route (billing alone)', ['billing'], fn () => ['rows' => collect($b['billing']['routes'])->filter(fn (array $r) => $r['billed_customers'] > 0)->sortByDesc(fn (array $r) => CrossStats::pct($r['est']['W']['customers'], $r['billed_customers']))->values()->map(fn (array $r) => ['label' => $r['label'], 'district' => $r['district'], 'billed' => $r['billed_customers'], 'wm_share' => CrossStats::pct($r['est']['W']['customers'], $r['billed_customers']), 'est_share' => $r['estimated_share']])->all()]);
    }

    // ---------------------------------------------------------------- X4

    protected function x4(array &$parts, array $b): void
    {
        $pct = (float) config('gwl.commercial_strength_mismatch_pct', 15);
        $this->part($parts, $b, 'reconciliation', 'Three counts side by side', ['reading', 'customers'], fn () => $this->analytics->x4($b['reading'], $b['billing'], $b['customers'], $pct));
        $this->part($parts, $b, 'strength', 'Verified strength by route (reading alone)', ['reading'], fn () => $this->analytics->x4($b['reading'], null, null, $pct));
    }

    // ---------------------------------------------------------------- X5

    protected function x5(array &$parts, array $b): void
    {
        $this->part($parts, $b, 'link', 'Accounts in one file and not the other, and what moved', ['link'], fn () => $this->analytics->x5($b['link'], CrossFileSources::districtNames()->all()));
    }

    // ---------------------------------------------------------------- X6

    protected function x6(array &$parts, array $b): void
    {
        $flags = CrossFileSources::statusFlags();
        $this->part($parts, $b, 'exceptions', 'Active accounts unbilled, billed accounts not active, HOLD accounts', ['billing'], fn () => $this->analytics->x6($b['billing'], null, $flags));
        $this->part($parts, $b, 'moved', 'Billed as active, now disconnected in the customer list', ['link'], fn () => $this->analytics->x6($b['billing'], $b['link'], $flags));
    }

    // ---------------------------------------------------------------- X7 and X11

    protected function targets(): array
    {
        return [
            'skip' => (float) config('gwl.commercial_target_skip_rate_pct', 15), 'coverage' => (float) config('gwl.commercial_target_coverage_pct', 95), 'collection' => (float) config('gwl.commercial_target_collection_pct', 90),
            'estimated_high' => (float) config('gwl.commercial_exception_high_estimation_pct', 25), 'unbilled_high' => (float) config('gwl.commercial_exception_high_unbilled_pct', 10),
        ];
    }

    protected function x7(array &$parts, array $b): void
    {
        // each side shows when the viewer may have it; a side they may not have is a hidden line, the others stay
        $parts['scorecard'] = $this->scorecardPart($b);
    }

    /** @return array<string, mixed> */
    protected function scorecardPart(array $b): array
    {
        $missing = array_values(array_filter(['reading', 'billing', 'customers'], fn (string $s) => ! $b['can'][$s]));
        $titles = ['reading' => 'the reading figures (commercial.view_reading)', 'billing' => 'the billing figures (commercial.view_billing)', 'customers' => 'the customer-list figures (commercial.view_customer_analytics)'];

        if (count($missing) === 3) {
            return ['key' => 'scorecard', 'title' => 'District scorecard', 'visible' => false, 'empty' => true, 'reason' => 'Hidden: it needs at least one of the reading, billing and customer-list permissions.', 'data' => null];
        }

        if ($b['reading'] === null && $b['billing'] === null && $b['customers'] === null) {
            return ['key' => 'scorecard', 'title' => 'District scorecard', 'visible' => true, 'empty' => true, 'reason' => 'No upload has been loaded for the parts you may see.', 'data' => null];
        }

        return [
            'key' => 'scorecard', 'title' => 'District scorecard', 'visible' => true, 'empty' => false, 'reason' => $missing === [] ? null : 'Hidden columns: '.implode('; ', array_map(fn (string $s) => $titles[$s], $missing)).'.',
            'data' => $this->analytics->x7($b['reading'], $b['billing'], $b['customers'], $this->targets()),
        ];
    }

    // ---------------------------------------------------------------- X8

    protected function x8(array &$parts, array $b): void
    {
        $this->part($parts, $b, 'portfolio', 'Arrears per CCA portfolio', ['billing', 'named'], fn () => $this->analytics->x8($b['billing']));
    }

    // ---------------------------------------------------------------- X9

    protected function x9(array &$parts, array $b): void
    {
        $this->part($parts, $b, 'routes', 'Read recency by route, beside reading coverage', ['billing'], fn () => $this->analytics->x9($b['reading'] ?? ['routes' => [], 'ccas' => []], $b['billing']));
        $this->part($parts, $b, 'ccas', 'Read recency per CCA, beside reading coverage', ['reading', 'billing', 'named'], fn () => ['rows' => $this->analytics->x9($b['reading'], $b['billing'])['ccas']]);
    }

    // ---------------------------------------------------------------- X10

    protected function x10(array &$parts, array $b): void
    {
        $this->part($parts, $b, 'coverage', 'Routes known to one file and not another', ['reading', 'billing', 'customers'], function () use ($b) {
            $ids = array_values(array_unique(array_merge(array_keys($b['reading']['routes']), array_keys($b['billing']['routes']), array_keys($b['customers']['routes']))));
            $districts = District::query()->pluck('district_name', 'id');
            $info = CommercialRoute::query()->whereIn('id', $ids)->get(['id', 'code', 'name', 'district_id'])
                ->mapWithKeys(fn ($r) => [$r->id => ['label' => trim(mb_strtoupper((string) ($districts[$r->district_id] ?? '')).' '.($r->code ?? $r->name)), 'district' => $districts[$r->district_id] ?? null]])->all();
            $out = $this->analytics->coverage($b['reading']['routes'], $b['billing']['routes'], $b['customers']['routes'], $info);
            $out['unplaced'] = ['reading' => count($b['reading']['unplaced']), 'billing' => count($b['billing']['unplaced'])];

            return $out;
        });

        $this->part($parts, $b, 'master', 'Route codes printed under more than one district, and route 0000', ['billing'], fn () => $this->routeMaster($b));
        $this->part($parts, $b, 'matrix', 'The district disagreement matrix', ['link'], fn () => $this->analytics->matrix($b['link']['matrix'], CrossFileSources::districtNames()->all()) + ['link_label' => $b['link']['label'], 'linked_on' => $b['link']['linked_on']]);
    }

    /**
     * From the route master (never from a file): the codes the region prints under more than one district (the canonical route and its aliases),
     * with the customers each side holds on them, and what sits on the "no route" code 0000.
     *
     * @return array<string, mixed>
     */
    protected function routeMaster(array $b): array
    {
        $regionId = (int) $b['region_id'];
        $aliases = CommercialRoute::query()->where('region_id', $regionId)->whereNotNull('canonical_route_id')->where('active', true)->get(['id', 'code', 'district_id', 'canonical_route_id']);
        $canon = CommercialRoute::query()->whereIn('id', $aliases->pluck('canonical_route_id')->unique()->all())->get(['id', 'code', 'district_id', 'source', 'pinned'])->keyBy('id');
        $names = District::query()->where('region_id', $regionId)->pluck('district_name', 'id');
        $mine = $this->actorDistrictIds();
        $rows = [];

        foreach ($aliases->groupBy('canonical_route_id') as $canonicalId => $group) {
            $c = $canon[$canonicalId] ?? null;

            if (! $c) {
                continue;
            }

            $districts = array_values(array_unique(array_merge([(int) $c->district_id], $group->pluck('district_id')->map(fn ($id) => (int) $id)->all())));

            if ($mine !== null && array_intersect($districts, $mine) === []) {
                continue;
            }

            $rows[] = [
                'code' => (string) $c->code, 'route_id' => (int) $c->id, 'canonical_district' => $names[$c->district_id] ?? 'District '.$c->district_id,
                'other_districts' => $group->map(fn ($r) => $names[$r->district_id] ?? 'District '.$r->district_id)->unique()->values()->all(), 'other_districts_text' => $group->map(fn ($r) => $names[$r->district_id] ?? 'District '.$r->district_id)->unique()->implode(', '), 'decided_by' => $c->pinned ? 'pinned by a manager' : 'the '.($c->source === CommercialRoute::SOURCE_CUSTOMER_LIST ? 'customer list' : 'billing / reading').' label',
                'billing_customers' => $b['billing']['routes'][$c->id]['customers'] ?? null, 'list_accounts' => $b['customers']['routes'][$c->id]['customers'] ?? null,
            ];
        }

        usort($rows, fn (array $x, array $y) => strcmp($x['code'], $y['code']));

        // route 0000: the code that means "no route"
        $zeroIds = CommercialRoute::query()->where('region_id', $regionId)->where('code', CommercialRoute::UNASSIGNED_CODE)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $zero = [
            'billing_customers' => array_sum(array_map(fn (int $id) => $b['billing']['routes'][$id]['customers'] ?? 0, $zeroIds)),
            'list_accounts' => array_sum(array_map(fn (int $id) => $b['customers']['routes'][$id]['customers'] ?? 0, $zeroIds)),
        ];

        return ['codes' => $rows, 'zero' => $zero, 'authority' => config('gwl.commercial_route_district_authority') === 'customer_list' ? 'customer list' : 'billing and reading'];
    }

    // ---------------------------------------------------------------- X11

    protected function x11(array &$parts, array $b): void
    {
        $parts['districts'] = $this->scorecardPart($b);
        $parts['districts']['title'] = 'Headline figures by district';

        $flags = [];
        $add = function (string $key, string $label, array $needs, int $count, string $link, array $query = []) use (&$flags, $b): void {
            if ($this->missing($needs, $b) !== [] || $this->absent($needs, $b) !== []) {
                return;
            }

            $flags[] = ['key' => $key, 'label' => $label, 'count' => $count, 'link' => $link, 'query' => $query + array_filter(['region' => $b['region_id'], 'district' => $b['district_id']])];
        };

        $reading = $b['reading'];
        $billing = $b['billing'];

        if ($reading !== null && $billing !== null) {
            $add('routes_no_cca', 'Routes billed but with no CCA reading them', ['reading', 'billing'], count(array_diff(array_keys($billing['routes']), array_keys($reading['routes']))), 'x10');
        }

        if ($reading !== null) {
            $add('ccas_no_visits', 'CCAs with routes and no visits', ['reading'], (int) ($reading['counts']['ccas_zero_visits'] ?? 0), 'x1');
            $add('skip_outliers', 'CCAs with an outlying skip rate', ['reading'], (int) ($reading['counts']['outliers'] ?? 0), 'x1');
            $add('unplaced_reading', 'Reading routes in a district nobody could place', ['reading'], count($reading['unplaced']), 'x10');
        }

        if ($billing !== null) {
            $add('active_unbilled', 'Active billing accounts that are unbilled', ['billing'], (int) $billing['totals']['unbilled_active'], 'x6');
            $add('routes_failing_totals', 'Routes that disagree with their own totals row', ['billing'], (int) array_sum(array_column($billing['integrity']['batches'], 'routes_failed')), 'x6');
            $add('unplaced_billing', 'Billing routes in a district nobody could place', ['billing'], count($billing['unplaced']), 'x10');
        }

        $stale = [];

        foreach (['reading' => 'commercial_reminder_reading_days', 'billing' => 'commercial_reminder_billing_days', 'customers' => 'commercial_customer_reminder_monthly_days'] as $side => $setting) {
            $w = $b['windows'][$side] ?? null;

            if ($w && $w['available'] && $w['to'] !== null && Carbon::parse($w['to'])->startOfDay()->diffInDays(now()->startOfDay(), false) > (int) config('gwl.'.$setting, 35)) {
                $stale[] = $w['title'].' ('.$w['label'].')';
            }
        }

        if ($b['any']) {
            $flags[] = ['key' => 'stale_uploads', 'label' => 'Uploads older than their reminder limit', 'count' => count($stale), 'link' => 'home', 'query' => [], 'detail' => $stale];
        }

        $parts['flags'] = ['key' => 'flags', 'title' => 'What needs attention', 'visible' => true, 'empty' => $flags === [], 'reason' => $flags === [] ? 'There is nothing to flag for the parts you may see, or nothing has been loaded.' : null, 'data' => ['flags' => $flags]];
    }

    // ---------------------------------------------------------------- X12

    protected function x12(array &$parts, array $b): void
    {
        $this->part($parts, $b, 'compare', 'Detailed billing against the billing summary', ['billing'], function () use ($b) {
            $w = $b['windows']['billing'];
            $summary = $this->summaryFigures((int) $b['region_id'], $w['from'], $w['to']);
            $details = [];

            foreach ($b['billing']['districts'] as $d) {
                if ($d['id'] !== null) {
                    $details[(int) $d['id']] = ['billed' => $d['billed_customers'], 'billing' => $d['month_total'], 'payments' => $d['paid'], 'closing' => $d['balance_due']];
                }
            }

            $names = CrossFileSources::districtNames()->all();
            $out = $this->analytics->x12($details, $summary['districts'], $names, ! $b['district_limited']);
            $out['summary_batches'] = $summary['batches'];
            $out['details_period'] = $w['label'];
            $out['available'] = $summary['batches'] !== [];

            return $out;
        });
    }

    /**
     * The billing summary's figures per district for the period of the detailed upload: the newest non-voided upload of each customer segment that
     * overlaps it. The summary can be a different cut (a segment, or a month where the details cover part of one): the batches used are named.
     *
     * @return array{districts: array<int, array<string, float|int>>, batches: list<array<string, mixed>>}
     */
    protected function summaryFigures(int $regionId, string $from, string $to): array
    {
        $batches = CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_BILLING_SUMMARY)->where('region_id', $regionId)->notVoided()
            ->whereDate('period_from', '<=', $to)->whereDate('period_to', '>=', $from)->orderBy('id')->get()
            ->groupBy('customer_segment')->map(fn ($group) => $group->last())->values();

        if ($batches->isEmpty()) {
            return ['districts' => [], 'batches' => []];
        }

        $rows = $this->scopeBillingRoutesForActor(CommercialBillingRoute::query()->whereIn('batch_id', $batches->pluck('id')->all()))->whereNotNull('district_id')->toBase()
            ->selectRaw('district_id, COALESCE(SUM(billed_total), 0) AS billed, COALESCE(SUM(billing_for_period), 0) AS billing, COALESCE(SUM(payment_for_month), 0) AS payments, COALESCE(SUM(closing_balance), 0) AS closing')
            ->groupBy('district_id')->get();

        $districts = [];

        foreach ($rows as $r) {
            $districts[(int) $r->district_id] = ['billed' => (int) $r->billed, 'billing' => round((float) $r->billing, 2), 'payments' => round((float) $r->payments, 2), 'closing' => round((float) $r->closing, 2)];
        }

        return [
            'districts' => $districts,
            'batches' => $batches->map(fn (CommercialImportBatch $x) => [
                'id' => (int) $x->id, 'segment' => (string) $x->customer_segment, 'period' => CommercialPeriods::describe($x->period_from, $x->period_to),
                'same_dates' => $x->period_from->toDateString() === $from && $x->period_to->toDateString() === $to,
            ])->all(),
        ];
    }

    // ---------------------------------------------------------------- X2: ROUTE 360, one route on one page

    /**
     * Everything the module knows about ONE route: its reading (owner CCA, the other CCAs listed with no visits, strength, read, skipped, visited,
     * unvisited, coverage, skip rate, trend by upload), its billing (bill-status mix, estimation cause, unbilled, consumption, charges, collections,
     * the balance owing and in credit, read recency, trend by upload), its customers (by status, service category and meter status, contact reachability,
     * faulty and missing meters) and the checks that tie the three together. Parts follow the permission rule like every analysis.
     *
     * A route outside the viewer's reach, or one that does not exist, is null (the screen answers 404, never 403, so the id of another district's route
     * is never confirmed). An alias is shown as the route it stands for.
     *
     * @param  array{from?: string|null, to?: string|null, mode?: string|null}  $q
     * @return array<string, mixed>|null
     */
    public function route(int $routeId, array $q = []): ?array
    {
        $found = CommercialRoute::query()->find($routeId);

        if (! $found) {
            return null;
        }

        $route = app(RouteResolver::class)->canonical($found);

        if (! $this->commercialScope()->seesDistrict($route->district_id !== null ? (int) $route->district_id : null, $route->region_id !== null ? (int) $route->region_id : null)) {
            return null;
        }

        $rid = (int) $route->id;
        $b = $this->sources->load(['region' => (int) $route->region_id, 'from' => $q['from'] ?? null, 'to' => $q['to'] ?? null, 'mode' => $q['mode'] ?? 'latest'], ['reading', 'billing', 'customers', 'link']);

        if ($b['region_id'] === null) {
            return null;
        }

        $reading = $b['reading']['routes'][$rid] ?? null;
        $billing = $b['billing']['routes'][$rid] ?? null;
        $list = $b['customers']['routes'][$rid] ?? null;
        $districtNames = District::query()->pluck('district_name', 'id');
        $aliases = CommercialRoute::query()->where('canonical_route_id', $rid)->get(['id', 'code', 'district_id', 'source']);
        $aliasIds = $aliases->pluck('id')->map(fn ($id) => (int) $id)->all();

        $head = [
            'id' => $rid, 'label' => $billing['label'] ?? ($reading['label'] ?? trim(($districtNames[$route->district_id] ?? '').' '.($route->code ?? $route->name))), 'code' => (string) ($route->code ?? $route->name),
            'district_id' => (int) $route->district_id, 'district' => $districtNames[$route->district_id] ?? null, 'source' => $route->source, 'pinned' => (bool) $route->pinned, 'pending' => (bool) $route->is_pending,
            'unassigned' => (string) $route->code === CommercialRoute::UNASSIGNED_CODE,
            'aliases' => $aliases->map(fn ($a) => ['district' => $districtNames[$a->district_id] ?? 'District '.$a->district_id, 'code' => (string) $a->code, 'source' => $a->source])->values()->all(),
            'opened_as_alias' => (int) $found->id !== $rid,
        ];

        $parts = [];
        $this->part($parts, $b, 'reading', 'Reading', ['reading'], fn () => $this->routeReading($rid, $reading, $b));
        $this->part($parts, $b, 'billing', 'Billing', ['billing'], fn () => $this->routeBilling($rid, $billing, $b));
        $this->part($parts, $b, 'customers', 'Customer list', ['customers'], fn () => $this->routeCustomers($rid, $list, $aliasIds));

        $parts['checks'] = ['key' => 'checks', 'title' => 'Checks', 'visible' => true, 'empty' => false, 'reason' => null, 'data' => $this->routeChecks($rid, $reading, $billing, $list, $b, $aliasIds, $head)];

        return [
            'route' => $head, 'region_id' => $b['region_id'], 'region' => $b['region'], 'district_limited' => $b['district_limited'], 'mode' => $b['mode'], 'typed' => $b['typed'], 'from' => $b['from'], 'to' => $b['to'],
            'can' => $b['can'], 'named' => $b['can']['named'], 'windows' => $b['windows'], 'parts' => $parts, 'notes' => $this->notes($b, ['reading', 'billing', 'customers']),
            'hidden' => array_values(array_map(fn (array $p) => ['title' => $p['title'], 'reason' => $p['reason']], array_filter($parts, fn (array $p) => ! $p['visible']))),
            'available' => $reading !== null || $billing !== null || $list !== null, 'cca_source' => $b['cca'], 'filters' => ['from' => $b['from'], 'to' => $b['to'], 'mode' => $b['mode']],
        ];
    }

    /** @return array<string, mixed> */
    protected function routeReading(int $rid, ?array $reading, array $b): array
    {
        if ($reading === null) {
            return ['present' => false, 'note' => 'No row of the reading upload names this route.'];
        }

        $named = $b['can']['named'];
        $rows = $reading['multi_rows'] ?? [];
        $others = array_values(array_filter($rows, fn (array $r) => ! $r['owner']));

        return [
            'present' => true, 'owner' => $named && $reading['owner_cca'] !== null ? ['staff_id' => $reading['owner_cca'], 'name' => $reading['owner_name']] : null,
            'others' => $named ? array_map(fn (array $r) => ['staff_id' => $r['cca'], 'name' => $r['name'], 'strength' => $r['strength'], 'visited' => $r['visited']], $others) : [],
            'other_count' => count($others),
            'strength' => $reading['strength'], 'read' => $reading['read'], 'skipped' => $reading['skipped'], 'visited' => $reading['visited'], 'unvisited' => $reading['unvisited'],
            'coverage' => $reading['coverage'], 'skip_rate' => $reading['skip_rate'], 'trend' => $this->routeReadingTrend($rid, (int) $b['region_id']),
        ];
    }

    /**
     * The route's reading, one point per upload (each alone, newest 12): its strength (the owner row's), read, skipped, visited.
     *
     * @return list<array<string, mixed>>
     */
    protected function routeReadingTrend(int $rid, int $regionId): array
    {
        $batches = CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY)->notVoided()->where('region_id', $regionId)->orderByDesc('period_to')->orderByDesc('id')->limit(12)->get()
            ->sortBy(fn ($x) => [$x->period_to->toDateString(), $x->id]);

        if ($batches->isEmpty()) {
            return [];
        }

        $rows = $this->scopeRouteStatsForActor(CommercialReadingRouteStat::query()->whereIn('batch_id', $batches->pluck('id')->all())->where('route_id', $rid))
            ->toBase()->get(['batch_id', 'verified_strength', 'read_count', 'skipped_count', 'visited_count', 'is_route_owner'])->groupBy('batch_id');

        $points = [];

        foreach ($batches as $batch) {
            $set = $rows[$batch->id] ?? collect();

            if ($set->isEmpty()) {
                continue;
            }

            $owner = $set->firstWhere('is_route_owner', 1) ?? $set->sortByDesc('visited_count')->first();
            $visited = (int) $set->sum('visited_count');
            $skipped = (int) $set->sum('skipped_count');
            $strength = (int) $owner->verified_strength;
            $points[] = [
                'batch_id' => (int) $batch->id, 'label' => CommercialPeriods::describe($batch->period_from, $batch->period_to), 'strength' => $strength, 'read' => (int) $set->sum('read_count'),
                'skipped' => $skipped, 'visited' => $visited, 'coverage' => CrossStats::pct($visited, $strength), 'skip_rate' => CrossStats::pct($skipped, $visited),
            ];
        }

        return $points;
    }

    /** @return array<string, mixed> */
    protected function routeBilling(int $rid, ?array $billing, array $b): array
    {
        if ($billing === null) {
            return ['present' => false, 'note' => 'No row of the detailed billing upload names this route.'];
        }

        $named = $b['can']['named'];

        return [
            'present' => true, 'row' => $billing, 'cca' => $named && $billing['cca_staff_id'] !== null ? ['staff_id' => $billing['cca_staff_id'], 'name' => $billing['cca_name']] : null,
            'cca_sentence' => $named && $b['cca'] ? $b['cca']['sentence'] : null, 'trend' => $this->sources->billingTrendOfRoute((int) $b['region_id'], $rid),
        ];
    }

    /**
     * @param  list<int>  $aliasIds
     * @return array<string, mixed>
     */
    protected function routeCustomers(int $rid, ?array $list, array $aliasIds): array
    {
        if ($list === null) {
            return ['present' => false, 'note' => 'The customer list holds no account on this route that you may see.'];
        }

        $db = DB::class;
        $labels = [
            'status' => $db::table('commercial_customer_statuses')->pluck('label', 'code')->all(),
            'category' => $db::table('commercial_customer_categories')->whereNotNull('code')->pluck('name', 'code')->all(),
            'meter' => $db::table('commercial_meter_statuses')->pluck('label', 'code')->all(),
        ];
        $reach = app(CustomerListService::class)->routeContactReach([$rid, ...$aliasIds], $this->customerRestriction(), $this->customerDistrictIds());
        $districts = District::query()->whereIn('id', array_keys($list['list_districts']))->pluck('district_name', 'id');

        return [
            'present' => true, 'customers' => $list['customers'], 'billing_accounts' => $list['billing'], 'metered' => $list['metered'], 'by_status' => $list['by_status'], 'by_category' => $list['by_category'], 'by_meter' => $list['by_meter'],
            'faulty' => $list['by_meter']['F'] ?? 0, 'no_meter' => $list['by_meter']['N'] ?? 0, 'labels' => $labels, 'reach' => $reach, 'reach_share' => CrossStats::pct($reach['reachable'], $reach['customers']),
            'list_districts' => collect($list['list_districts'])->mapWithKeys(fn ($n, $id) => [$districts[$id] ?? 'District '.$id => $n])->all(), 'aliased' => $list['aliased'],
        ];
    }

    /**
     * What ties the three files together for this route.
     *
     * @param  list<int>  $aliasIds
     * @param  array<string, mixed>  $head
     * @return array<string, mixed>
     */
    protected function routeChecks(int $rid, ?array $reading, ?array $billing, ?array $list, array $b, array $aliasIds, array $head): array
    {
        $out = ['strength' => null, 'accounts' => null, 'alias' => ['is_alias' => $head['opened_as_alias'], 'has_aliases' => $head['aliases'] !== [], 'aliases' => $head['aliases']]];

        if ($reading !== null && $b['can']['reading']) {
            $billingSide = $b['can']['billing'] && $billing !== null ? ['routes' => [$rid => $billing]] : null;
            $listSide = $b['can']['customers'] && $list !== null ? ['routes' => [$rid => $list]] : null;
            $out['strength'] = $this->analytics->x4(['routes' => [$rid => $reading]], $billingSide, $listSide, (float) config('gwl.commercial_strength_mismatch_pct', 15))['rows'][0] ?? null;
        }

        if ($b['can']['billing'] && $b['can']['customers'] && $b['link'] !== null) {
            $byRoute = $b['link']['by_route'][$rid] ?? null;
            $counts = app(CustomerListService::class)->routeLinkCounts($rid, [$rid, ...$aliasIds], $this->customerRestriction(), $this->customerDistrictIds());
            $out['accounts'] = [
                'billing_accounts' => $byRoute['accounts'] ?? ($billing['customers'] ?? null), 'in_billing_not_in_list' => $byRoute !== null ? $byRoute['accounts'] - $byRoute['in_list'] : null,
                'list_accounts' => $counts['list_accounts'], 'in_list_not_on_this_bill' => $counts['not_billed_here'], 'link_label' => $b['link']['label'],
            ];
        }

        return $out;
    }
}
