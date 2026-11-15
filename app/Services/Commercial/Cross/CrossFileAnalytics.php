<?php

namespace App\Services\Commercial\Cross;

/**
 * The analyses X1 and X3 to X12 as PURE passes over what CrossFileSources loaded (design 8.42): nothing here reads the database or the
 * signed-in user, so every figure can be checked by hand, and the screen, the Excel file and the PDF (all of which call CrossFileReport)
 * cannot disagree.
 *
 * Inputs (all already scoped to the viewer by the single-file reports they come from):
 *   $reading   routes (route id => reading route row), ccas, districts, ...     (ReadingRouteAnalyticsService rows)
 *   $billing   routes (route id => billing wide row incl. cca_staff_id), districts, totals, lists   (BillingDetailsAnalytics rows)
 *   $customers routes (route id => breakdown), districts                        (CustomerAnalyticsService::routeBreakdown / districtBase)
 *   $link      transitions, matrix, by_district, list_billing_not_in_file, status_changed_accounts   (what the billing link stored)
 *
 * Money is in cedis (as the reports give it), ratios are percentages recomputed from sums (never an average of ratios) and null on a zero
 * denominator. A negative balance is a customer credit and is never netted off what customers owe. Which CCA stands beside a route is decided
 * by the caller: the READING upload's owner for the analyses led by reading (X1, X3, X4, X9), RouteCcaResolver's for those led by billing
 * (X6, X8).
 */
class CrossFileAnalytics
{
    /** A district needs this many routes before a correlation is worked out for it on its own (X3). */
    public const MIN_DISTRICT_ROUTES = 10;

    /** The status codes that mean "disconnected" for X6 (the lookups' own flag is is_active = false, which also holds suspended and transferred). */
    public const DISCONNECTED = ['DISC', 'DISN'];

    // ---------------------------------------------------------------- X1: CCA performance against billing outcomes

    /**
     * Per CCA (the routes it OWNS in the reading upload): the reading figures beside the billing figures on those routes, ranked on skip rate.
     *
     * @param  array<string, mixed>  $reading
     * @param  array<string, mixed>|null  $billing
     * @return array{rows: list<array<string, mixed>>, scatter: list<array<string, mixed>>}
     */
    public function x1(array $reading, ?array $billing): array
    {
        $billed = $billing['routes'] ?? [];
        $sums = [];

        foreach ($reading['routes'] as $routeId => $route) {
            $staff = $route['owner_cca'];

            if ($staff === null || ! isset($billed[$routeId])) {
                continue;
            }

            $sums[$staff] = $this->addBilling($sums[$staff] ?? $this->emptyBilling(), $billed[$routeId]);
        }

        $rows = [];

        foreach ($reading['ccas'] as $staff => $c) {
            if ($c['owned_routes'] < 1) {
                continue;
            }

            $rows[] = [
                'staff_id' => (string) $staff, 'name' => $c['name'], 'label' => trim($staff.' '.$c['name']), 'district' => $c['district'], 'owned_routes' => $c['owned_routes'],
                'visited' => $c['visited'], 'skipped' => $c['skipped'], 'skip_rate' => $c['skip_rate'], 'coverage' => $c['coverage'], 'unvisited' => $c['unvisited'],
                'eligible' => (bool) $c['eligible'], 'rank' => $c['rank'],
                'billing' => isset($sums[$staff]) && $sums[$staff]['routes'] > 0 ? $this->billingFigures($sums[$staff]) : null,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['rank'] ?? PHP_INT_MAX, $a['staff_id']] <=> [$b['rank'] ?? PHP_INT_MAX, $b['staff_id']]);

        $scatter = [];

        foreach ($rows as $r) {
            if ($r['skip_rate'] !== null && $r['billing'] !== null && $r['billing']['wm_share'] !== null) {
                $scatter[] = ['label' => $r['label'], 'x' => $r['skip_rate'], 'y' => $r['billing']['wm_share']];
            }
        }

        return ['rows' => $rows, 'scatter' => $scatter];
    }

    /**
     * The billing figures alone, per CCA (the owner of each billing route for the bill period, RouteCcaResolver): the part of X1 a viewer without
     * the reading permission still sees. A route with no CCA is one row of its own.
     *
     * @param  array<string, mixed>  $billing
     * @return list<array<string, mixed>>
     */
    public function billingByCca(array $billing): array
    {
        $by = [];

        foreach ($billing['routes'] as $b) {
            $key = $b['cca_staff_id'] ?? '';
            $p = &$by[$key];
            $p ??= ['staff_id' => $key, 'name' => $b['cca_name'], 'sums' => $this->emptyBilling()];
            $p['sums'] = $this->addBilling($p['sums'], $b);
            unset($p);
        }

        $rows = array_map(fn (array $p) => [
            'staff_id' => $p['staff_id'], 'name' => $p['name'], 'label' => $p['staff_id'] === '' ? '(no CCA)' : trim($p['staff_id'].' '.$p['name']),
        ] + $this->billingFigures($p['sums']), array_values($by));

        usort($rows, fn (array $a, array $b) => [$a['staff_id'] === '' ? 1 : 0, $a['staff_id']] <=> [$b['staff_id'] === '' ? 1 : 0, $b['staff_id']]);

        return $rows;
    }

    /** @return array<string, int|float> */
    protected function emptyBilling(): array
    {
        return ['routes' => 0, 'customers' => 0, 'billed' => 0, 'estimated' => 0, 'wm' => 0, 'unbilled' => 0, 'month_total' => 0.0, 'paid' => 0.0, 'owing_amount' => 0.0, 'owing' => 0, 'credit_amount' => 0.0, 'balance_due' => 0.0];
    }

    /**
     * @param  array<string, int|float>  $acc
     * @param  array<string, mixed>  $row  a billing route row
     * @return array<string, int|float>
     */
    protected function addBilling(array $acc, array $row): array
    {
        $acc['routes']++;
        $acc['customers'] += $row['customers'];
        $acc['billed'] += $row['billed_customers'];
        $acc['estimated'] += $row['estimated_customers'];
        $acc['wm'] += $row['est']['W']['customers'];
        $acc['unbilled'] += $row['unbilled'];
        $acc['month_total'] += $row['month_total'];
        $acc['paid'] += $row['paid'];
        $acc['owing_amount'] += $row['owing_amount'];
        $acc['owing'] += $row['owing'];
        $acc['credit_amount'] += $row['credit_amount'];
        $acc['balance_due'] += $row['balance_due'];

        return $acc;
    }

    /**
     * @param  array<string, int|float>  $a
     * @return array<string, mixed>
     */
    protected function billingFigures(array $a): array
    {
        return [
            'routes' => $a['routes'], 'customers' => $a['customers'], 'billed' => $a['billed'], 'estimated_share' => CrossStats::pct($a['estimated'], $a['billed']), 'wm_share' => CrossStats::pct($a['wm'], $a['billed']),
            'unbilled_share' => CrossStats::pct($a['unbilled'], $a['customers']), 'collection_ratio' => CrossStats::pct($a['paid'], $a['month_total']), 'month_total' => round($a['month_total'], 2),
            'paid' => round($a['paid'], 2), 'owing_amount' => round($a['owing_amount'], 2), 'owing' => $a['owing'], 'credit_amount' => round($a['credit_amount'], 2), 'balance_due' => round($a['balance_due'], 2),
        ];
    }

    // ---------------------------------------------------------------- X3: skip against estimation, route by route

    /**
     * For the routes read AND billed with at least $minCustomers billing customers: the correlation of the route's skip rate with its share of bills
     * estimated on a WORKING meter and with its share of all bills estimated, over every route and per district (a district needs 10 routes), and the
     * routes that are high on one and low on the other (the top quarter against the bottom quarter). A correlation, not a cause.
     *
     * @param  array<string, mixed>  $reading
     * @param  array<string, mixed>  $billing
     * @return array<string, mixed>
     */
    public function x3(array $reading, array $billing, int $minCustomers): array
    {
        $points = [];
        $skippedSmall = 0;
        $noVisits = 0;

        foreach ($reading['routes'] as $routeId => $r) {
            $b = $billing['routes'][$routeId] ?? null;

            if ($b === null || $b['billed_customers'] < 1) {
                continue;
            }

            if ($r['skip_rate'] === null) {
                $noVisits++;

                continue;
            }

            if ($b['customers'] < $minCustomers) {
                $skippedSmall++;

                continue;
            }

            $points[] = [
                'route_id' => $routeId, 'label' => $b['label'], 'district_id' => $b['district_id'], 'district' => $b['district'], 'customers' => $b['customers'], 'billed' => $b['billed_customers'],
                'skip_rate' => (float) $r['skip_rate'], 'wm_share' => (float) CrossStats::pct($b['est']['W']['customers'], $b['billed_customers']), 'est_share' => (float) CrossStats::pct($b['estimated_customers'], $b['billed_customers']),
            ];
        }

        usort($points, fn (array $a, array $b) => [$a['district'], $a['label']] <=> [$b['district'], $b['label']]);

        $coefficients = fn (array $set) => [
            'routes' => count($set),
            'r_wm' => CrossStats::pearson(array_column($set, 'skip_rate'), array_column($set, 'wm_share')),
            'r_est' => CrossStats::pearson(array_column($set, 'skip_rate'), array_column($set, 'est_share')),
        ];

        $byDistrict = [];

        foreach (collect($points)->groupBy('district_id') as $districtId => $set) {
            $set = $set->values()->all();
            $row = ['district_id' => (int) $districtId, 'district' => $set[0]['district']] + $coefficients($set);
            $row['enough'] = $row['routes'] >= self::MIN_DISTRICT_ROUTES;

            if (! $row['enough']) {
                $row['r_wm'] = $row['r_est'] = null;
            }

            $byDistrict[] = $row;
        }

        usort($byDistrict, fn (array $a, array $b) => strcmp((string) $a['district'], (string) $b['district']));

        return [
            'points' => $points, 'overall' => $coefficients($points), 'by_district' => $byDistrict, 'min_customers' => $minCustomers, 'min_district_routes' => self::MIN_DISTRICT_ROUTES,
            'left_out_small' => $skippedSmall, 'left_out_no_visits' => $noVisits, 'divergent' => $this->divergent($points),
        ];
    }

    /**
     * Routes in the top quarter of the skip rate and the bottom quarter of the working-meter estimate share, and the other way round. Needs at
     * least 4 routes; the quarters are the 25th and 75th percentiles (linear interpolation).
     *
     * @param  list<array<string, mixed>>  $points
     * @return array{skip_high_estimate_low: list<array<string, mixed>>, skip_low_estimate_high: list<array<string, mixed>>, thresholds: array<string, float>|null}
     */
    protected function divergent(array $points): array
    {
        if (count($points) < 4) {
            return ['skip_high_estimate_low' => [], 'skip_low_estimate_high' => [], 'thresholds' => null];
        }

        $skip = array_column($points, 'skip_rate');
        $wm = array_column($points, 'wm_share');
        $t = ['skip_q1' => CrossStats::percentile($skip, 0.25), 'skip_q3' => CrossStats::percentile($skip, 0.75), 'wm_q1' => CrossStats::percentile($wm, 0.25), 'wm_q3' => CrossStats::percentile($wm, 0.75)];

        $highLow = array_values(array_filter($points, fn (array $p) => $p['skip_rate'] >= $t['skip_q3'] && $p['wm_share'] <= $t['wm_q1']));
        $lowHigh = array_values(array_filter($points, fn (array $p) => $p['skip_rate'] <= $t['skip_q1'] && $p['wm_share'] >= $t['wm_q3']));

        return ['skip_high_estimate_low' => $highLow, 'skip_low_estimate_high' => $lowHigh, 'thresholds' => $t];
    }

    // ---------------------------------------------------------------- X4: strength reconciliation

    /**
     * For every route with a verified strength: the strength, the customer list's METERED accounts (working or faulty meter, any status) and the
     * customers billing has on the route, three counts side by side; their differences, the medians, and the routes beyond the mismatch percentage.
     *
     * @param  array<string, mixed>  $reading
     * @param  array<string, mixed>|null  $billing
     * @param  array<string, mixed>|null  $customers
     * @return array<string, mixed>
     */
    public function x4(array $reading, ?array $billing, ?array $customers, float $mismatchPct): array
    {
        $rows = [];

        foreach ($reading['routes'] as $routeId => $r) {
            $metered = $customers['routes'][$routeId]['metered'] ?? null;
            $aliased = (bool) ($customers['routes'][$routeId]['aliased'] ?? false);
            $billed = $billing['routes'][$routeId]['customers'] ?? null;
            $pctMetered = $metered !== null && $metered > 0 ? CrossStats::pct(abs($r['strength'] - $metered), $metered) : null;
            $pctBilling = $billed !== null && $billed > 0 ? CrossStats::pct(abs($r['strength'] - $billed), $billed) : null;

            $rows[] = [
                'route_id' => $routeId, 'label' => $r['label'], 'district_id' => $r['district_id'], 'district' => $r['district'], 'owner_cca' => $r['owner_cca'], 'owner_name' => $r['owner_name'],
                'strength' => $r['strength'], 'metered' => $metered, 'billing_customers' => $billed, 'aliased' => $aliased, 'aliased_text' => $aliased ? 'yes' : '',
                'diff_metered' => $metered !== null ? $r['strength'] - $metered : null, 'pct_metered' => $pctMetered,
                'diff_billing' => $billed !== null ? $r['strength'] - $billed : null, 'pct_billing' => $pctBilling,
                'metered_vs_billing' => $metered !== null && $billed !== null ? $metered - $billed : null,
                'beyond' => $metered !== null && ($metered > 0 ? ($pctMetered > $mismatchPct) : $r['strength'] > 0),
            ];
        }

        usort($rows, fn (array $a, array $b) => [(string) $a['district'], (string) $a['label']] <=> [(string) $b['district'], (string) $b['label']]);

        $all3 = array_values(array_filter($rows, fn (array $r) => $r['metered'] !== null && $r['billing_customers'] !== null));
        $median = fn (string $key, array $set, bool $abs = false) => CrossStats::median(array_map(fn (array $r) => $abs ? abs($r[$key]) : $r[$key], array_values(array_filter($set, fn (array $r) => $r[$key] !== null))));

        return [
            'rows' => $rows,
            'totals' => [
                'routes_with_all_three' => count($all3), 'strength' => array_sum(array_column($all3, 'strength')), 'metered' => array_sum(array_column($all3, 'metered')),
                'billing_customers' => array_sum(array_column($all3, 'billing_customers')),
            ],
            'medians' => [
                'strength_minus_metered' => $median('diff_metered', $rows), 'strength_minus_billing' => $median('diff_billing', $rows), 'metered_minus_billing' => $median('metered_vs_billing', $rows),
                'pct_metered' => $median('pct_metered', $rows), 'pct_billing' => $median('pct_billing', $rows),
            ],
            'beyond' => array_values(array_filter($rows, fn (array $r) => $r['beyond'])), 'mismatch_pct' => $mismatchPct,
            'compared_metered' => count(array_filter($rows, fn (array $r) => $r['metered'] !== null)), 'compared_billing' => count(array_filter($rows, fn (array $r) => $r['billing_customers'] !== null)),
        ];
    }

    // ---------------------------------------------------------------- X5: billing against the customer list

    /**
     * What the link said: accounts in billing and not in the list (per billing district), the list's billing-status accounts not in the file (per
     * list district), and how many linked accounts changed status or service category between the bill and the list (a from-to matrix per
     * district), with the capped list of the accounts whose status changed.
     *
     * @param  array<string, mixed>  $link
     * @param  array<int, string>  $districtNames
     * @return array<string, mixed>
     */
    public function x5(array $link, array $districtNames): array
    {
        $name = fn (?int $id) => $id === null ? '–' : ($districtNames[$id] ?? 'District '.$id);

        $accounts = [];

        foreach ($link['by_district'] as $id => $row) {
            $accounts[] = ['district_id' => (int) $id, 'district' => $name((int) $id)] + $row;
        }

        usort($accounts, fn (array $a, array $b) => strcmp($a['district'], $b['district']));

        $listNot = [];

        foreach ($link['list_billing_not_in_file'] as $id => $n) {
            $listNot[] = ['district_id' => (int) $id, 'district' => $name((int) $id), 'accounts' => (int) $n];
        }

        usort($listNot, fn (array $a, array $b) => strcmp($a['district'], $b['district']));

        $perDistrict = [];

        foreach ($link['transitions'] as $t) {
            $d = &$perDistrict[$t['district_id']];
            $d ??= ['district_id' => $t['district_id'], 'district' => $name($t['district_id']), 'compared' => 0, 'status_changed' => 0, 'category_changed' => 0, 'status' => [], 'category' => []];
            $d['compared'] += $t['customers'];
            $d['status'][$t['from_status']][$t['to_status']] = ($d['status'][$t['from_status']][$t['to_status']] ?? 0) + $t['customers'];
            $d['category'][$t['from_category']][$t['to_category']] = ($d['category'][$t['from_category']][$t['to_category']] ?? 0) + $t['customers'];
            $d['status_changed'] += $t['from_status'] !== $t['to_status'] ? $t['customers'] : 0;
            $d['category_changed'] += $t['from_category'] !== $t['to_category'] ? $t['customers'] : 0;
            unset($d);
        }

        $perDistrict = array_values($perDistrict);
        usort($perDistrict, fn (array $a, array $b) => strcmp($a['district'], $b['district']));

        return [
            'accounts' => $accounts, 'list_not_in_file' => $listNot, 'districts' => $perDistrict,
            'matrix' => ['status' => $this->flatMatrix($perDistrict, 'status'), 'category' => $this->flatMatrix($perDistrict, 'category')],
            'totals' => [
                'compared' => array_sum(array_column($perDistrict, 'compared')), 'status_changed' => array_sum(array_column($perDistrict, 'status_changed')), 'category_changed' => array_sum(array_column($perDistrict, 'category_changed')),
            ],
            'changed_accounts' => array_map(fn (array $a) => $a + ['district' => $name($a['district_id'])], $link['status_changed_accounts']),
            'region_totals' => $link['totals'],
        ];
    }

    /**
     * One row per (district, from, to): the from-to matrix of a dimension as a flat list, changes first.
     *
     * @param  list<array<string, mixed>>  $perDistrict
     * @return list<array<string, mixed>>
     */
    protected function flatMatrix(array $perDistrict, string $dimension): array
    {
        $out = [];

        foreach ($perDistrict as $d) {
            foreach ($d[$dimension] as $from => $tos) {
                foreach ($tos as $to => $n) {
                    $out[] = ['district_id' => $d['district_id'], 'district' => $d['district'], 'from' => (string) $from, 'to' => (string) $to, 'customers' => $n, 'changed' => (string) $from !== (string) $to, 'moved_text' => (string) $from !== (string) $to ? 'moved' : ''];
                }
            }
        }

        usort($out, fn (array $a, array $b) => [$a['district'], $a['changed'] ? 0 : 1, -$a['customers'], $a['from'], $a['to']] <=> [$b['district'], $b['changed'] ? 0 : 1, -$b['customers'], $b['from'], $b['to']]);

        return $out;
    }

    // ---------------------------------------------------------------- X6: status exceptions with their CCA and route

    /**
     * Per route and per CCA (the owner RouteCcaResolver gave the billing rows): active billing-status accounts that are unbilled, billed accounts
     * whose status is not active (a disconnected account that was billed), HOLD accounts, and (from the link) accounts billed as active that the
     * list now shows disconnected. Rates are of the figure that makes sense for each (active accounts, billed accounts, all customers, linked accounts).
     *
     * @param  array<string, mixed>  $billing
     * @param  array<string, mixed>|null  $link
     * @param  array<string, array{active: bool, billing: bool, label: string}>  $flags
     * @return array<string, mixed>
     */
    public function x6(array $billing, ?array $link, array $flags): array
    {
        $now = [];

        foreach ($link['transitions'] ?? [] as $t) {
            $r = &$now[$t['route_id']];
            $r ??= ['compared' => 0, 'active_then_disconnected' => 0];
            $r['compared'] += $t['customers'];

            if (($flags[$t['from_status']]['active'] ?? false) && in_array($t['to_status'], self::DISCONNECTED, true)) {
                $r['active_then_disconnected'] += $t['customers'];
            }

            unset($r);
        }

        $routeRows = [];
        $byCca = [];

        foreach ($billing['routes'] as $routeId => $b) {
            $moved = $link !== null ? ($now[$routeId] ?? ['compared' => 0, 'active_then_disconnected' => 0]) : null;
            $row = [
                'route_id' => $routeId, 'label' => $b['label'], 'district_id' => $b['district_id'], 'district' => $b['district'], 'cca_staff_id' => $b['cca_staff_id'], 'cca_name' => $b['cca_name'],
                'customers' => $b['customers'], 'active_billing' => $b['active_billing_customers'], 'unbilled_active' => $b['unbilled_active'], 'unbilled_active_rate' => CrossStats::pct($b['unbilled_active'], $b['active_billing_customers']),
                'billed' => $b['billed_customers'], 'billed_not_active' => $b['billed_not_active'], 'billed_not_active_rate' => CrossStats::pct($b['billed_not_active'], $b['billed_customers']),
                'hold' => $b['hold_customers'], 'hold_rate' => CrossStats::pct($b['hold_customers'], $b['customers']),
                'compared' => $moved['compared'] ?? null, 'now_disconnected' => $moved['active_then_disconnected'] ?? null, 'now_disconnected_rate' => $moved !== null ? CrossStats::pct($moved['active_then_disconnected'], $moved['compared']) : null,
            ];
            $routeRows[] = $row;

            $key = $b['cca_staff_id'] ?? '';
            $a = &$byCca[$key];
            $a ??= ['staff_id' => $key, 'name' => $b['cca_name'], 'routes' => 0, 'customers' => 0, 'active_billing' => 0, 'unbilled_active' => 0, 'billed' => 0, 'billed_not_active' => 0, 'hold' => 0, 'compared' => $link !== null ? 0 : null, 'now_disconnected' => $link !== null ? 0 : null];
            $a['routes']++;

            foreach (['customers', 'active_billing', 'unbilled_active', 'billed', 'billed_not_active', 'hold'] as $k) {
                $a[$k] += $row[$k];
            }

            if ($link !== null) {
                $a['compared'] += $row['compared'];
                $a['now_disconnected'] += $row['now_disconnected'];
            }

            unset($a);
        }

        usort($routeRows, fn (array $x, array $y) => [(string) $x['district'], (string) $x['label']] <=> [(string) $y['district'], (string) $y['label']]);

        $ccaRows = array_map(fn (array $a) => $a + [
            'unbilled_active_rate' => CrossStats::pct($a['unbilled_active'], $a['active_billing']), 'billed_not_active_rate' => CrossStats::pct($a['billed_not_active'], $a['billed']),
            'hold_rate' => CrossStats::pct($a['hold'], $a['customers']), 'now_disconnected_rate' => $a['compared'] !== null ? CrossStats::pct($a['now_disconnected'], $a['compared']) : null,
        ], array_values($byCca));
        usort($ccaRows, fn (array $x, array $y) => [$x['staff_id'] === '' ? 1 : 0, $x['staff_id']] <=> [$y['staff_id'] === '' ? 1 : 0, $y['staff_id']]);

        $sum = fn (string $k) => array_sum(array_column($routeRows, $k));

        return [
            'routes' => $routeRows, 'ccas' => $ccaRows,
            'totals' => [
                'active_billing' => $sum('active_billing'), 'unbilled_active' => $sum('unbilled_active'), 'billed_not_active' => $sum('billed_not_active'), 'hold' => $sum('hold'),
                'now_disconnected' => $link !== null ? $sum('now_disconnected') : null, 'compared' => $link !== null ? $sum('compared') : null,
            ],
            'lists' => ['active_unbilled' => $billing['lists']['active_unbilled'] ?? null, 'billed_not_active' => $billing['lists']['billed_not_active'] ?? null],
        ];
    }

    // ---------------------------------------------------------------- X7: the district scorecard from the real routes

    /**
     * One row per district with its reading, billing and customer-list figures, each colour-coded against the targets and thresholds of the
     * Settings: skip rate and coverage against their targets, collection ratio against its target, the estimated and unbilled shares against the
     * "high" thresholds. No composite score: a figure is good, bad or without a target to judge it by.
     *
     * @param  array<string, mixed>|null  $reading
     * @param  array<string, mixed>|null  $billing
     * @param  array<string, mixed>|null  $customers
     * @param  array<string, float|int>  $targets  skip, coverage, collection, estimated_high, unbilled_high
     * @return array<string, mixed>
     */
    public function x7(?array $reading, ?array $billing, ?array $customers, array $targets): array
    {
        $ids = [];

        foreach ($reading['districts'] ?? [] as $d) {
            if ($d['id'] !== null) {
                $ids[$d['id']] = $d['name'];
            }
        }

        foreach ($billing['districts'] ?? [] as $d) {
            if ($d['id'] !== null) {
                $ids[$d['id']] = $d['name'];
            }
        }

        foreach ($customers['districts'] ?? [] as $id => $d) {
            $ids[$id] ??= CrossFileSources::districtNames()[$id] ?? 'District '.$id;
        }

        $readingBy = collect($reading['districts'] ?? [])->keyBy('id');
        $billingBy = collect($billing['districts'] ?? [])->keyBy('id');
        $judge = fn (?float $value, ?float $target, string $direction) => $value === null || $target === null ? null : ($direction === 'max' ? ($value <= $target ? 'good' : 'bad') : ($value >= $target ? 'good' : 'bad'));

        $rows = [];

        foreach ($ids as $id => $name) {
            $r = $readingBy[$id] ?? null;
            $b = $billingBy[$id] ?? null;
            $c = $customers['districts'][$id] ?? null;
            $held = (bool) ($r['held_back'] ?? false);

            $row = [
                'district_id' => (int) $id, 'district' => $name,
                'reading' => $reading === null ? null : ($r === null || $held ? ['held_back' => $held, 'coverage' => null, 'skip_rate' => null, 'unvisited' => null] : ['held_back' => false, 'coverage' => $r['coverage'], 'skip_rate' => $r['skip_rate'], 'unvisited' => $r['unvisited'], 'strength' => $r['strength']]),
                'billing' => $billing === null || $b === null ? null : [
                    'customers' => $b['customers'], 'estimated_share' => $b['estimated_share'], 'unbilled_share' => $b['unbilled_share'], 'collection_ratio' => $b['collection_ratio'],
                    'owing_amount' => $b['owing_amount'], 'owing_share' => CrossStats::pct($b['owing_amount'], $b['month_total']), 'month_total' => $b['month_total'],
                ],
                'customers' => $customers === null || $c === null ? null : ['base' => $c['customers'], 'billing' => $c['billing'], 'contactable_share' => $c['contactable_share']],
            ];

            $row['judged'] = [
                'skip_rate' => $judge($row['reading']['skip_rate'] ?? null, $targets['skip'] ?? null, 'max'),
                'coverage' => $judge($row['reading']['coverage'] ?? null, $targets['coverage'] ?? null, 'min'),
                'collection_ratio' => $judge($row['billing']['collection_ratio'] ?? null, $targets['collection'] ?? null, 'min'),
                'estimated_share' => $judge($row['billing']['estimated_share'] ?? null, $targets['estimated_high'] ?? null, 'max'),
                'unbilled_share' => $judge($row['billing']['unbilled_share'] ?? null, $targets['unbilled_high'] ?? null, 'max'),
            ];
            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b) => strcmp($a['district'], $b['district']));

        return ['rows' => $rows, 'targets' => $targets];
    }

    // ---------------------------------------------------------------- X8: arrears per CCA portfolio

    /**
     * Per CCA (owner of the billing route, RouteCcaResolver): the owing balance, the owing accounts, the credits, the collection ratio and the share of
     * the viewer's owing balance on the routes the CCA owns, and the routes holding the most of its arrears. A negative balance is a credit and is
     * never netted off the owing. Routes with no CCA are one portfolio of their own.
     *
     * @param  array<string, mixed>  $billing
     * @return array<string, mixed>
     */
    public function x8(array $billing, int $topRoutes = 3): array
    {
        $by = [];

        foreach ($billing['routes'] as $routeId => $b) {
            $key = $b['cca_staff_id'] ?? '';
            $p = &$by[$key];
            $p ??= ['staff_id' => $key, 'name' => $b['cca_name'], 'sums' => $this->emptyBilling(), 'routes' => []];
            $p['sums'] = $this->addBilling($p['sums'], $b);
            $p['routes'][] = ['route_id' => $routeId, 'label' => $b['label'], 'district' => $b['district'], 'owing_amount' => $b['owing_amount'], 'owing' => $b['owing']];
            unset($p);
        }

        $total = round(array_sum(array_map(fn (array $p) => $p['sums']['owing_amount'], $by)), 2);
        $rows = [];

        foreach ($by as $p) {
            $routes = $p['routes'];
            usort($routes, fn (array $a, array $b) => [-$a['owing_amount'], $a['label']] <=> [-$b['owing_amount'], $b['label']]);
            $owing = round($p['sums']['owing_amount'], 2);

            $rows[] = [
                'staff_id' => $p['staff_id'], 'name' => $p['name'], 'label' => $p['staff_id'] === '' ? '(no CCA)' : trim($p['staff_id'].' '.$p['name']), 'routes' => $p['sums']['routes'], 'customers' => $p['sums']['customers'],
                'owing_amount' => $owing, 'owing' => $p['sums']['owing'], 'credit_amount' => round($p['sums']['credit_amount'], 2), 'collection_ratio' => CrossStats::pct($p['sums']['paid'], $p['sums']['month_total']),
                'share_of_owing' => CrossStats::pct($owing, $total),
                'top_routes' => array_map(fn (array $r) => $r + ['share_of_cca' => CrossStats::pct($r['owing_amount'], $owing)], array_slice(array_values(array_filter($routes, fn (array $r) => $r['owing_amount'] > 0)), 0, $topRoutes)),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['staff_id'] === '' ? 1 : 0, -$a['owing_amount'], $a['staff_id']] <=> [$b['staff_id'] === '' ? 1 : 0, -$b['owing_amount'], $b['staff_id']]);

        return ['rows' => $rows, 'owing_total' => $total];
    }

    // ---------------------------------------------------------------- X9: read recency against visits

    /**
     * The billing read-recency bands (days from the last read to the end of the bill period: 0-30, 31-60, 61-90, over 90, never) beside the reading
     * coverage and unvisited of the same route and of the same CCA (the owner in the reading upload).
     *
     * @param  array<string, mixed>  $reading
     * @param  array<string, mixed>  $billing
     * @return array<string, mixed>
     */
    public function x9(array $reading, array $billing): array
    {
        $routes = [];
        $byCca = [];

        foreach ($billing['routes'] as $routeId => $b) {
            $r = $reading['routes'][$routeId] ?? null;
            $row = [
                'route_id' => $routeId, 'label' => $b['label'], 'district' => $b['district'], 'district_id' => $b['district_id'], 'owner_cca' => $r['owner_cca'] ?? null, 'owner_name' => $r['owner_name'] ?? null,
                'customers' => $b['customers'], 'read_0_30' => $b['read_0_30'], 'read_31_60' => $b['read_31_60'], 'read_61_90' => $b['read_61_90'], 'read_over_90' => $b['read_over_90'], 'read_never' => $b['read_never'],
                'stale_share' => CrossStats::pct($b['read_over_90'] + $b['read_never'], $b['customers']),
                'strength' => $r['strength'] ?? null, 'visited' => $r['visited'] ?? null, 'coverage' => $r['coverage'] ?? null, 'unvisited' => $r['unvisited'] ?? null, 'has_reading' => $r !== null,
            ];
            $routes[] = $row;

            if ($r !== null && $r['owner_cca'] !== null) {
                $c = &$byCca[$r['owner_cca']];
                $c ??= ['staff_id' => $r['owner_cca'], 'name' => $r['owner_name'], 'routes' => 0, 'customers' => 0, 'read_0_30' => 0, 'read_31_60' => 0, 'read_61_90' => 0, 'read_over_90' => 0, 'read_never' => 0];
                $c['routes']++;

                foreach (['customers', 'read_0_30', 'read_31_60', 'read_61_90', 'read_over_90', 'read_never'] as $k) {
                    $c[$k] += $row[$k];
                }

                unset($c);
            }
        }

        usort($routes, fn (array $a, array $b) => [(string) $a['district'], (string) $a['label']] <=> [(string) $b['district'], (string) $b['label']]);

        $ccas = [];

        foreach ($byCca as $staff => $c) {
            $reading_c = $reading['ccas'][$staff] ?? null;
            $ccas[] = $c + [
                'label' => trim($staff.' '.$c['name']), 'stale_share' => CrossStats::pct($c['read_over_90'] + $c['read_never'], $c['customers']),
                'coverage' => $reading_c['coverage'] ?? null, 'unvisited' => $reading_c['unvisited'] ?? null, 'visited' => $reading_c['visited'] ?? null,
            ];
        }

        usort($ccas, fn (array $a, array $b) => strcmp($a['staff_id'], $b['staff_id']));

        return ['routes' => $routes, 'ccas' => $ccas];
    }

    // ---------------------------------------------------------------- X10: which system owns a route's district (the evidence)

    /**
     * The district disagreement matrix: rows are the district the customer LIST puts accounts in, columns the district their BILLING route is in,
     * with the diagonal (agreement) marked and each row's share. Counts of linked accounts only.
     *
     * @param  list<array{billing_district_id: int, list_district_id: int, customers: int}>  $cells
     * @param  array<int, string>  $districtNames
     * @return array<string, mixed>
     */
    public function matrix(array $cells, array $districtNames): array
    {
        // rows: the districts the LIST puts accounts in; columns: the districts the BILLING puts their routes in (only those that have a cell, so a viewer
        // limited to one district is not shown a row of another's)
        $rowsIds = array_values(array_unique(array_column($cells, 'list_district_id')));
        $colsIds = array_values(array_unique(array_column($cells, 'billing_district_id')));
        $byName = fn (int $a, int $b) => strcmp($districtNames[$a] ?? '', $districtNames[$b] ?? '');
        usort($rowsIds, $byName);
        usort($colsIds, $byName);

        $grid = [];

        foreach ($cells as $c) {
            $grid[$c['list_district_id']][$c['billing_district_id']] = $c['customers'];
        }

        $rows = [];
        $agree = 0;
        $total = 0;

        foreach ($rowsIds as $listId) {
            $rowTotal = array_sum($grid[$listId] ?? []);
            $line = [];

            foreach ($colsIds as $billingId) {
                $n = $grid[$listId][$billingId] ?? 0;
                $line[] = ['billing_district_id' => $billingId, 'customers' => $n, 'share' => CrossStats::pct($n, $rowTotal), 'diagonal' => $listId === $billingId];
            }

            $rows[] = ['list_district_id' => $listId, 'list_district' => $districtNames[$listId] ?? 'District '.$listId, 'total' => $rowTotal, 'cells' => $line, 'agree' => $grid[$listId][$listId] ?? 0, 'agree_share' => CrossStats::pct($grid[$listId][$listId] ?? 0, $rowTotal)];
            $agree += $grid[$listId][$listId] ?? 0;
            $total += $rowTotal;
        }

        return [
            'columns' => array_map(fn (int $id) => ['district_id' => $id, 'district' => $districtNames[$id] ?? 'District '.$id], $colsIds), 'rows' => $rows,
            'agree' => $agree, 'total' => $total, 'agree_share' => CrossStats::pct($agree, $total), 'disagree' => $total - $agree, 'disagree_share' => CrossStats::pct($total - $agree, $total),
        ];
    }

    /**
     * Routes known to one file and not another, from the three route sets (canonical route id => label).
     *
     * @param  array<int, array<string, mixed>>  $reading  route id => reading route
     * @param  array<int, array<string, mixed>>  $billing  route id => billing route
     * @param  array<int, array<string, mixed>>  $listRoutes  route id => customer breakdown
     * @param  array<int, array{label: string, district: string|null}>  $routeInfo  route id => the route master's label and district name (for a route no report names)
     * @return array<string, mixed>
     */
    public function coverage(array $reading, array $billing, array $listRoutes, array $routeInfo): array
    {
        $label = fn (int $id) => $billing[$id]['label'] ?? ($reading[$id]['label'] ?? ($routeInfo[$id]['label'] ?? 'route '.$id));
        $pick = function (array $ids) use ($label, $billing, $reading, $routeInfo): array {
            $rows = array_map(fn (int $id) => ['route_id' => $id, 'label' => $label($id), 'district' => $billing[$id]['district'] ?? ($reading[$id]['district'] ?? ($routeInfo[$id]['district'] ?? null))], $ids);
            usort($rows, fn (array $a, array $b) => [(string) $a['district'], $a['label']] <=> [(string) $b['district'], $b['label']]);

            return $rows;
        };

        $inReading = array_keys($reading);
        $inBilling = array_keys($billing);
        $inList = array_keys($listRoutes);

        return [
            'billing_without_reading' => $pick(array_values(array_diff($inBilling, $inReading))),
            'reading_without_billing' => $pick(array_values(array_diff($inReading, $inBilling))),
            'list_in_neither' => $pick(array_values(array_diff($inList, $inReading, $inBilling))),
        ];
    }

    // ---------------------------------------------------------------- X12: the detailed billing against the billing summary

    /**
     * Customers billed, billing for the period, payments and closing balance of the Detailed Billing Report against the same measures of the older
     * billing summary, per district and in total, with the differences. Both sides arrive as district id => figures.
     *
     * @param  array<int, array<string, float|int>>  $details
     * @param  array<int, array<string, float|int>>  $summary
     * @param  array<int, string>  $districtNames
     * @return array<string, mixed>
     */
    public function x12(array $details, array $summary, array $districtNames, bool $withTotal): array
    {
        $measures = [
            'billed' => 'Customers billed', 'billing' => 'Billing for the period (GH¢)', 'payments' => 'Payments this month (GH¢)', 'closing' => 'Closing balance (GH¢)',
        ];

        $ids = array_values(array_unique(array_merge(array_keys($details), array_keys($summary))));
        usort($ids, fn (int $a, int $b) => strcmp($districtNames[$a] ?? '', $districtNames[$b] ?? ''));

        $line = function (string $name, ?array $d, ?array $s) use ($measures): array {
            $cells = [];

            foreach (array_keys($measures) as $m) {
                $dv = $d[$m] ?? null;
                $sv = $s[$m] ?? null;
                $diff = $dv !== null && $sv !== null ? round($dv - $sv, 2) : null;
                $cells[$m] = ['details' => $dv, 'summary' => $sv, 'difference' => $diff, 'difference_pct' => $diff !== null ? CrossStats::pct($diff, abs($sv)) : null];
            }

            return ['district' => $name, 'cells' => $cells, 'both' => $d !== null && $s !== null];
        };

        $rows = array_map(fn (int $id) => $line($districtNames[$id] ?? 'District '.$id, $details[$id] ?? null, $summary[$id] ?? null) + ['district_id' => $id], $ids);

        $total = null;

        if ($withTotal) {
            $sum = fn (array $set, string $m) => $set === [] ? null : round(array_sum(array_column($set, $m)), 2);
            $total = $line('All districts', $details === [] ? null : array_combine(array_keys($measures), array_map(fn ($m) => $sum($details, $m), array_keys($measures))), $summary === [] ? null : array_combine(array_keys($measures), array_map(fn ($m) => $sum($summary, $m), array_keys($measures))));
        }

        return ['measures' => $measures, 'rows' => $rows, 'total' => $total];
    }
}
