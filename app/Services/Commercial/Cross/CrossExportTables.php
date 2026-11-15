<?php

namespace App\Services\Commercial\Cross;

use App\Services\Commercial\BillingDetails\BillingDetailsAnalytics;

/**
 * The tables of the Excel / PDF file of each "Across the files" analysis (Phase 8e, design 8.42), built from the SAME payload the page shows
 * (CrossFileReport::build / route), so a file cannot disagree with its page. A part the viewer may not see is not in the file: a table named
 * "Hidden" says what is missing instead. Cells are plain numbers or strings, '' for no value (CommercialExportService / ReportSheet keep text as text).
 */
class CrossExportTables
{
    /**
     * @param  array<string, mixed>  $d  CrossFileReport::build()
     * @return list<array{title: string, headings: list<string>, rows: list<list<mixed>>}>
     */
    public function tables(string $key, array $d): array
    {
        $tables = match ($key) {
            'x1' => $this->x1($d),
            'x3' => $this->x3($d),
            'x4' => $this->x4($d),
            'x5' => $this->x5($d),
            'x6' => $this->x6($d),
            'x7' => $this->scorecard($d, 'scorecard'),
            'x8' => $this->x8($d),
            'x9' => $this->x9($d),
            'x10' => $this->x10($d),
            'x11' => array_merge($this->scorecard($d, 'districts'), $this->flags($d)),
            'x12' => $this->x12($d),
            default => [],
        };

        $tables = array_merge($tables, $this->hiddenTable($d));

        return $tables === [] ? [['title' => 'Nothing to show', 'headings' => ['Note'], 'rows' => [['Nothing has been loaded for the parts you may see, or the range holds no upload.']]]] : $tables;
    }

    /** @return list<array<string, mixed>> */
    protected function hiddenTable(array $d): array
    {
        $rows = [];

        foreach ($d['parts'] as $part) {
            if (! $part['visible']) {
                $rows[] = [$part['title'], (string) $part['reason']];
            } elseif ($part['empty']) {
                $rows[] = [$part['title'], (string) $part['reason']];
            }
        }

        return $rows === [] ? [] : [['title' => 'Not in this file', 'headings' => ['Part', 'Why'], 'rows' => $rows]];
    }

    /** @param  array<string, mixed>  $d */
    protected function data(array $d, string $part): ?array
    {
        $p = $d['parts'][$part] ?? null;

        return $p && $p['visible'] && ! $p['empty'] ? $p['data'] : null;
    }

    protected function n(mixed $value): mixed
    {
        return $value ?? '';
    }

    // ---------------------------------------------------------------- X1

    protected function x1(array $d): array
    {
        $out = [];

        if ($c = $this->data($d, 'combined')) {
            $out[] = ['title' => 'X1 CCA performance beside billing outcomes', 'headings' => ['CCA', 'District', 'Routes', 'Visited', 'Skip rate (%)', 'Coverage (%)', 'Unvisited', 'Rank', 'Billing customers', 'Estimated bills (%)', 'Estimated on a working meter (%)', 'Unbilled (%)', 'Collection ratio (%)', 'Owing (GH¢)', 'Owing accounts'],
                'rows' => array_map(fn (array $r) => [$r['label'], (string) $r['district'], $r['owned_routes'], $r['visited'], $this->n($r['skip_rate']), $this->n($r['coverage']), $r['unvisited'], $this->n($r['rank']), $this->n($r['billing']['customers'] ?? null), $this->n($r['billing']['estimated_share'] ?? null), $this->n($r['billing']['wm_share'] ?? null), $this->n($r['billing']['unbilled_share'] ?? null), $this->n($r['billing']['collection_ratio'] ?? null), $this->n($r['billing']['owing_amount'] ?? null), $this->n($r['billing']['owing'] ?? null)], $c['rows'])];
            $out[] = ['title' => 'X1 Scatter points (skip rate, share estimated on a working meter)', 'headings' => ['CCA', 'Skip rate (%)', 'Estimated on a working meter (%)'], 'rows' => array_map(fn (array $p) => [$p['label'], $p['x'], $p['y']], $c['scatter'])];
        }

        if ($r = $this->data($d, 'reading')) {
            $out[] = ['title' => 'X1 Reading figures per CCA', 'headings' => ['CCA', 'District', 'Routes', 'Visited', 'Skipped', 'Skip rate (%)', 'Coverage (%)', 'Unvisited', 'Rank'],
                'rows' => array_map(fn (array $x) => [$x['label'], (string) $x['district'], $x['owned_routes'], $x['visited'], $x['skipped'], $this->n($x['skip_rate']), $this->n($x['coverage']), $x['unvisited'], $this->n($x['rank'])], $r['rows'])];
        }

        if ($b = $this->data($d, 'billing')) {
            $out[] = ['title' => 'X1 Billing figures per CCA (routes owned for the bill period)', 'headings' => ['CCA', 'Routes', 'Customers', 'Estimated bills (%)', 'Estimated on a working meter (%)', 'Unbilled (%)', 'Collection ratio (%)', 'Owing (GH¢)', 'Owing accounts'],
                'rows' => array_map(fn (array $x) => [$x['label'], $x['routes'], $x['customers'], $this->n($x['estimated_share']), $this->n($x['wm_share']), $this->n($x['unbilled_share']), $this->n($x['collection_ratio']), $x['owing_amount'], $x['owing']], $b['rows'])];
        }

        return $out;
    }

    // ---------------------------------------------------------------- X3

    protected function x3(array $d): array
    {
        $out = [];

        if ($c = $this->data($d, 'correlation')) {
            $out[] = ['title' => 'X3 Correlation (a correlation, not a cause)', 'headings' => ['Measure', 'Value'], 'rows' => [
                ['Routes used', $c['overall']['routes']], ['Skip rate against working-meter estimates (Pearson)', $this->n($c['overall']['r_wm'])], ['Skip rate against all estimates (Pearson)', $this->n($c['overall']['r_est'])],
                ['Smallest route used (billing customers)', $c['min_customers']], ['Routes left out: too small', $c['left_out_small']], ['Routes left out: no visits', $c['left_out_no_visits']],
            ]];
            $out[] = ['title' => 'X3 Correlation by district (at least '.$c['min_district_routes'].' routes)', 'headings' => ['District', 'Routes', 'Skip rate vs working-meter estimates', 'Skip rate vs all estimates'], 'rows' => array_map(fn (array $r) => [(string) $r['district'], $r['routes'], $this->n($r['r_wm']), $this->n($r['r_est'])], $c['by_district'])];
            $out[] = ['title' => 'X3 The routes used', 'headings' => ['Route', 'District', 'Customers', 'Billed', 'Skip rate (%)', 'Estimated on a working meter (%)', 'All estimates (%)'], 'rows' => array_map(fn (array $p) => [$p['label'], (string) $p['district'], $p['customers'], $p['billed'], $p['skip_rate'], $p['wm_share'], $p['est_share']], $c['points'])];
            $out[] = ['title' => 'X3 Skip rate high, estimates on working meters low', 'headings' => ['Route', 'District', 'Skip rate (%)', 'Estimated on a working meter (%)'], 'rows' => array_map(fn (array $p) => [$p['label'], (string) $p['district'], $p['skip_rate'], $p['wm_share']], $c['divergent']['skip_high_estimate_low'])];
            $out[] = ['title' => 'X3 Skip rate low, estimates on working meters high', 'headings' => ['Route', 'District', 'Skip rate (%)', 'Estimated on a working meter (%)'], 'rows' => array_map(fn (array $p) => [$p['label'], (string) $p['district'], $p['skip_rate'], $p['wm_share']], $c['divergent']['skip_low_estimate_high'])];
        }

        if ($s = $this->data($d, 'skip')) {
            $out[] = ['title' => 'X3 Skip rate by route (reading alone)', 'headings' => ['Route', 'District', 'Visited', 'Skip rate (%)'], 'rows' => array_map(fn (array $r) => [$r['label'], (string) $r['district'], $r['visited'], $r['skip_rate']], $s['rows'])];
        }

        if ($e = $this->data($d, 'estimates')) {
            $out[] = ['title' => 'X3 Estimates on working meters by route (billing alone)', 'headings' => ['Route', 'District', 'Billed', 'Estimated on a working meter (%)', 'All estimates (%)'], 'rows' => array_map(fn (array $r) => [$r['label'], (string) $r['district'], $r['billed'], $this->n($r['wm_share']), $this->n($r['est_share'])], $e['rows'])];
        }

        return $out;
    }

    // ---------------------------------------------------------------- X4

    protected function x4(array $d): array
    {
        $out = [];
        $rows = fn (array $set) => array_map(fn (array $r) => [$r['label'], (string) $r['district'], (string) ($r['owner_name'] ?? ''), $r['strength'], $this->n($r['metered']), $this->n($r['billing_customers']), $this->n($r['diff_metered']), $this->n($r['pct_metered']), $this->n($r['diff_billing']), $this->n($r['pct_billing']), $r['aliased'] ? 'yes' : ''], $set);
        $heads = ['Route', 'District', 'CCA', 'Verified strength', 'Metered accounts (list)', 'Billing customers', 'Strength − metered', 'Off the metered (%)', 'Strength − billing', 'Off the billing (%)', 'Code printed under another district'];

        if ($c = $this->data($d, 'reconciliation')) {
            $m = $c['medians'];
            $out[] = ['title' => 'X4 Summary', 'headings' => ['Measure', 'Value'], 'rows' => [
                ['Routes with all three counts', $c['totals']['routes_with_all_three']], ['Strength (those routes)', $c['totals']['strength']], ['Metered accounts (those routes)', $c['totals']['metered']], ['Billing customers (those routes)', $c['totals']['billing_customers']],
                ['Median strength − metered', $this->n($m['strength_minus_metered'])], ['Median strength − billing', $this->n($m['strength_minus_billing'])], ['Median metered − billing', $this->n($m['metered_minus_billing'])],
                ['Median difference from the metered (%)', $this->n($m['pct_metered'])], ['Median difference from billing (%)', $this->n($m['pct_billing'])], ['Limit (%)', $c['mismatch_pct']], ['Routes beyond the limit', count($c['beyond'])],
            ]];
            $out[] = ['title' => 'X4 Three counts side by side', 'headings' => $heads, 'rows' => $rows($c['rows'])];
            $out[] = ['title' => 'X4 Routes beyond the limit', 'headings' => $heads, 'rows' => $rows($c['beyond'])];
        } elseif ($s = $this->data($d, 'strength')) {
            $out[] = ['title' => 'X4 Verified strength by route', 'headings' => $heads, 'rows' => $rows($s['rows'])];
        }

        return $out;
    }

    // ---------------------------------------------------------------- X5

    protected function x5(array $d): array
    {
        $l = $this->data($d, 'link');

        if (! $l) {
            return [];
        }

        return [
            ['title' => 'X5 Accounts in billing and not in the list', 'headings' => ['District of the billing route', 'Accounts in billing', 'Found in the list', 'Not in the list'], 'rows' => array_map(fn (array $r) => [$r['district'], $r['accounts'], $r['in_list'], $r['not_in_list']], $l['accounts'])],
            ['title' => 'X5 Billing-status accounts in the list and not in the billing file', 'headings' => ['District the list puts them in', 'Accounts'], 'rows' => array_map(fn (array $r) => [$r['district'], $r['accounts']], $l['list_not_in_file'])],
            ['title' => 'X5 Accounts compared and moved, per district', 'headings' => ['District', 'Compared', 'Status changed', 'Category changed'], 'rows' => array_map(fn (array $r) => [$r['district'], $r['compared'], $r['status_changed'], $r['category_changed']], $l['districts'])],
            ['title' => 'X5 Status from the bill to the list', 'headings' => ['District', 'Status on the bill', 'Status in the list', 'Accounts', 'Moved'], 'rows' => array_map(fn (array $r) => [$r['district'], $r['from'], $r['to'], $r['customers'], $r['moved_text']], $l['matrix']['status'])],
            ['title' => 'X5 Service category from the bill to the list', 'headings' => ['District', 'Category on the bill', 'Category in the list', 'Accounts', 'Moved'], 'rows' => array_map(fn (array $r) => [$r['district'], $r['from'], $r['to'], $r['customers'], $r['moved_text']], $l['matrix']['category'])],
            ['title' => 'X5 Accounts whose status moved (account numbers only, capped per district)', 'headings' => ['Account', 'Route', 'District'], 'rows' => array_map(fn (array $r) => [(string) $r['account'], $r['route'], (string) $r['district']], array_slice($l['changed_accounts'], 0, max(1, (int) config('gwl.commercial_billing_detail_issue_cap', 200))))],
        ];
    }

    // ---------------------------------------------------------------- X6

    protected function x6(array $d): array
    {
        $out = [];
        $named = $d['named'];

        if ($e = $this->data($d, 'exceptions')) {
            if ($named) {
                $out[] = ['title' => 'X6 Status exceptions per CCA', 'headings' => ['CCA', 'Name', 'Routes', 'Active, unbilled', 'Rate (% of active)', 'Billed, not active', 'Rate (% of billed)', 'On HOLD', 'Rate (% of customers)'],
                    'rows' => array_map(fn (array $r) => [(string) $r['staff_id'], (string) $r['name'], $r['routes'], $r['unbilled_active'], $this->n($r['unbilled_active_rate']), $r['billed_not_active'], $this->n($r['billed_not_active_rate']), $r['hold'], $this->n($r['hold_rate'])], $e['ccas'])];
            }

            $out[] = ['title' => 'X6 Status exceptions per route', 'headings' => ['Route', 'District', ...($named ? ['CCA'] : []), 'Customers', 'Active, unbilled', 'Rate (%)', 'Billed, not active', 'Rate (%)', 'On HOLD', 'Rate (%)'],
                'rows' => array_map(fn (array $r) => [$r['label'], (string) $r['district'], ...($named ? [trim($r['cca_staff_id'].' '.$r['cca_name'])] : []), $r['customers'], $r['unbilled_active'], $this->n($r['unbilled_active_rate']), $r['billed_not_active'], $this->n($r['billed_not_active_rate']), $r['hold'], $this->n($r['hold_rate'])], $e['routes'])];

            foreach (['active_unbilled' => 'X6 Active accounts that are unbilled', 'billed_not_active' => 'X6 Billed accounts that are not active'] as $kind => $title) {
                if ($list = ($e['lists'][$kind] ?? null)) {
                    $out[] = ['title' => $title, 'headings' => ['Account', 'Route', 'District', ...($named ? ['CCA'] : []), $list['amount_label']],
                        'rows' => array_map(fn (array $r) => [(string) $r['account'], $r['route'], (string) ($r['district'] ?? ''), ...($named ? [(string) ($r['cca'] ?? '')] : []), $r['amount']], array_slice($list['rows'], 0, max(1, (int) config('gwl.commercial_billing_detail_issue_cap', 200))))];
                }
            }
        }

        if ($m = $this->data($d, 'moved')) {
            $out[] = ['title' => 'X6 Billed as active, now disconnected, per route', 'headings' => ['Route', 'District', ...($named ? ['CCA'] : []), 'Linked accounts', 'Now disconnected', 'Rate (%)'],
                'rows' => array_map(fn (array $r) => [$r['label'], (string) $r['district'], ...($named ? [trim($r['cca_staff_id'].' '.$r['cca_name'])] : []), $this->n($r['compared']), $this->n($r['now_disconnected']), $this->n($r['now_disconnected_rate'])], array_values(array_filter($m['routes'], fn ($r) => ($r['now_disconnected'] ?? 0) > 0)))];
        }

        return $out;
    }

    // ---------------------------------------------------------------- X7 and X11

    protected function scorecard(array $d, string $part): array
    {
        $s = $this->data($d, $part);

        if (! $s) {
            return [];
        }

        $t = $s['targets'];

        return [
            ['title' => 'District scorecard', 'headings' => ['District', 'Coverage (%)', 'Skip rate (%)', 'Unvisited', 'Estimated bills (%)', 'Unbilled (%)', 'Collection ratio (%)', 'Owing (GH¢)', 'Owing, share of the month total (%)', 'Customers', 'Billing accounts', 'Contactable (%)', 'Outside the target'],
                'rows' => array_map(function (array $r) {
                    $bad = array_keys(array_filter($r['judged'], fn ($j) => $j === 'bad'));

                    return [$r['district'], $this->n($r['reading']['coverage'] ?? null), $this->n($r['reading']['skip_rate'] ?? null), $this->n($r['reading']['unvisited'] ?? null), $this->n($r['billing']['estimated_share'] ?? null), $this->n($r['billing']['unbilled_share'] ?? null), $this->n($r['billing']['collection_ratio'] ?? null), $this->n($r['billing']['owing_amount'] ?? null), $this->n($r['billing']['owing_share'] ?? null), $this->n($r['customers']['base'] ?? null), $this->n($r['customers']['billing'] ?? null), $this->n($r['customers']['contactable_share'] ?? null), implode(', ', array_map(fn ($k) => str_replace('_', ' ', $k), $bad))];
                }, $s['rows'])],
            ['title' => 'Targets and thresholds used', 'headings' => ['Measure', 'Value'], 'rows' => [['Skip rate at most (%)', $t['skip']], ['Coverage at least (%)', $t['coverage']], ['Collection ratio at least (%)', $t['collection']], ['Estimated bills high above (%)', $t['estimated_high']], ['Unbilled high above (%)', $t['unbilled_high']]]],
        ];
    }

    protected function flags(array $d): array
    {
        $f = $this->data($d, 'flags');

        if (! $f) {
            return [];
        }

        return [['title' => 'What needs attention', 'headings' => ['Flag', 'Count', 'Where', 'Detail'], 'rows' => array_map(fn (array $x) => [$x['label'], $x['count'], strtoupper((string) $x['link']), implode('; ', $x['detail'] ?? [])], $f['flags'])]];
    }

    // ---------------------------------------------------------------- X8

    protected function x8(array $d): array
    {
        $p = $this->data($d, 'portfolio');

        if (! $p) {
            return [];
        }

        return [
            ['title' => 'X8 Arrears per CCA portfolio', 'headings' => ['CCA', 'Routes', 'Customers', 'Owing (GH¢)', 'Owing accounts', 'Credits (GH¢)', 'Collection ratio (%)', 'Share of the owing balance (%)'],
                'rows' => array_map(fn (array $r) => [$r['label'], $r['routes'], $r['customers'], $r['owing_amount'], $r['owing'], $r['credit_amount'], $this->n($r['collection_ratio']), $this->n($r['share_of_owing'])], $p['rows'])],
            ['title' => 'X8 The routes holding most of each CCA\'s arrears', 'headings' => ['CCA', 'Route', 'District', 'Owing (GH¢)', 'Owing accounts', 'Share of the CCA\'s arrears (%)'],
                'rows' => array_merge([], ...array_map(fn (array $r) => array_map(fn (array $t) => [$r['label'], $t['label'], (string) $t['district'], $t['owing_amount'], $t['owing'], $this->n($t['share_of_cca'])], $r['top_routes']), $p['rows']))],
        ];
    }

    // ---------------------------------------------------------------- X9

    protected function x9(array $d): array
    {
        $out = [];
        $named = $d['named'];

        if ($r = $this->data($d, 'routes')) {
            $out[] = ['title' => 'X9 Read recency by route', 'headings' => ['Route', 'District', ...($named ? ['CCA (reading)'] : []), 'Customers', '0 to 30 days', '31 to 60', '61 to 90', 'Over 90', 'Never read', 'Over 90 or never (%)', 'Reading coverage (%)', 'Unvisited'],
                'rows' => array_map(fn (array $x) => [$x['label'], (string) $x['district'], ...($named ? [(string) ($x['owner_name'] ?? '')] : []), $x['customers'], $x['read_0_30'], $x['read_31_60'], $x['read_61_90'], $x['read_over_90'], $x['read_never'], $this->n($x['stale_share']), $this->n($x['coverage']), $this->n($x['unvisited'])], $r['routes'])];
        }

        if ($c = $this->data($d, 'ccas')) {
            $out[] = ['title' => 'X9 Read recency per CCA', 'headings' => ['CCA', 'Routes', 'Customers', 'Over 90 days', 'Never read', 'Over 90 or never (%)', 'Reading coverage (%)', 'Unvisited'],
                'rows' => array_map(fn (array $x) => [$x['label'], $x['routes'], $x['customers'], $x['read_over_90'], $x['read_never'], $this->n($x['stale_share']), $this->n($x['coverage']), $this->n($x['unvisited'])], $c['rows'])];
        }

        return $out;
    }

    // ---------------------------------------------------------------- X10

    protected function x10(array $d): array
    {
        $out = [];

        if ($m = $this->data($d, 'matrix')) {
            $out[] = ['title' => 'X10 The district disagreement matrix (rows: the list\'s district; columns: the billing district)', 'headings' => ['List district', ...array_column($m['columns'], 'district'), 'Accounts', 'Agree (%)'],
                'rows' => array_map(fn (array $r) => [$r['list_district'], ...array_map(fn (array $c) => $c['customers'], $r['cells']), $r['total'], $this->n($r['agree_share'])], $m['rows'])];
            $out[] = ['title' => 'X10 Matrix summary', 'headings' => ['Measure', 'Value'], 'rows' => [['Linked accounts', $m['total']], ['Same district in both', $m['agree']], ['Same district (%)', $this->n($m['agree_share'])], ['Different district', $m['disagree']], ['Different district (%)', $this->n($m['disagree_share'])]]];
        }

        if ($x = $this->data($d, 'master')) {
            $out[] = ['title' => 'X10 Codes printed under more than one district', 'headings' => ['Code', 'District used', 'Also printed under', 'Decided by', 'Customers in billing', 'Accounts in the list'], 'rows' => array_map(fn (array $r) => [(string) $r['code'], $r['canonical_district'], $r['other_districts_text'], $r['decided_by'], $this->n($r['billing_customers']), $this->n($r['list_accounts'])], $x['codes'])];
            $out[] = ['title' => 'X10 Route 0000 (the code that means no route)', 'headings' => ['Measure', 'Value'], 'rows' => [['Customers in billing', $x['zero']['billing_customers']], ['Accounts in the list', $x['zero']['list_accounts']]]];
        }

        if ($c = $this->data($d, 'coverage')) {
            foreach (['billing_without_reading' => 'X10 Routes in billing without a reading row (no CCA)', 'reading_without_billing' => 'X10 Reading routes absent from billing', 'list_in_neither' => 'X10 Customer-list routes in neither billing nor reading'] as $key => $title) {
                $out[] = ['title' => $title, 'headings' => ['Route', 'District'], 'rows' => array_map(fn (array $r) => [$r['label'], (string) $r['district']], $c[$key])];
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- X12

    protected function x12(array $d): array
    {
        $c = $this->data($d, 'compare');

        if (! $c) {
            return [];
        }

        if (! $c['available']) {
            return [['title' => 'X12 Nothing to compare', 'headings' => ['Note'], 'rows' => [['No billing summary lies over '.$c['details_period'].'.']]]];
        }

        $heads = ['District'];

        foreach ($c['measures'] as $label) {
            array_push($heads, $label.': details', $label.': summary', $label.': difference', $label.': difference (%)');
        }

        $lines = array_merge($c['rows'], $c['total'] ? [$c['total']] : []);

        return [
            ['title' => 'X12 Detailed billing against the billing summary ('.$c['details_period'].')', 'headings' => $heads, 'rows' => array_map(function (array $l) use ($c) {
                $row = [$l['district']];

                foreach (array_keys($c['measures']) as $key) {
                    $x = $l['cells'][$key];
                    array_push($row, $this->n($x['details']), $this->n($x['summary']), $this->n($x['difference']), $this->n($x['difference_pct']));
                }

                return $row;
            }, $lines)],
            ['title' => 'X12 Billing summary uploads used', 'headings' => ['Batch', 'Segment', 'Period', 'Same dates as the details'], 'rows' => array_map(fn (array $b) => ['#'.$b['id'], $b['segment'], $b['period'], $b['same_dates'] ? 'yes' : 'no'], $c['summary_batches'])],
        ];
    }

    // ---------------------------------------------------------------- route 360

    /**
     * @param  array<string, mixed>  $d  CrossFileReport::route()
     * @return list<array<string, mixed>>
     */
    public function routeTables(array $d): array
    {
        $route = $d['route'];
        $out = [['title' => 'Route', 'headings' => ['Measure', 'Value'], 'rows' => [
            ['Route', $route['label']], ['District', (string) $route['district']], ['Code', $route['code']], ['Printed under another district', $route['aliases'] === [] ? 'no' : implode(', ', array_map(fn (array $a) => $a['district'], $route['aliases']))],
        ]]];

        if ($r = $this->data($d, 'reading')) {
            if ($r['present']) {
                $out[] = ['title' => 'Reading', 'headings' => ['Measure', 'Value'], 'rows' => [
                    ['Owner CCA', $r['owner'] ? trim($r['owner']['staff_id'].' '.$r['owner']['name']) : ''], ['Other CCAs listed with no visits', $r['other_count']], ['Verified strength', $r['strength']], ['Read', $r['read']], ['Skipped', $r['skipped']], ['Visited', $r['visited']],
                    ['Unvisited', $r['unvisited']], ['Coverage (%)', $this->n($r['coverage'])], ['Skip rate (%)', $this->n($r['skip_rate'])],
                ]];
                $out[] = ['title' => 'Reading by upload', 'headings' => ['Period', 'Strength', 'Read', 'Skipped', 'Visited', 'Coverage (%)', 'Skip rate (%)'], 'rows' => array_map(fn (array $p) => [$p['label'], $p['strength'], $p['read'], $p['skipped'], $p['visited'], $this->n($p['coverage']), $this->n($p['skip_rate'])], $r['trend'])];
            }
        }

        if ($b = $this->data($d, 'billing')) {
            if ($b['present']) {
                $x = $b['row'];
                $rows = [['Customers', $x['customers']], ['Billed customers', $x['billed_customers']]];

                foreach (['REGULAR' => 'Regular', 'ESTIMATED' => 'Estimated', 'ESTIMATE' => 'Estimate', 'UNBILLED' => 'Unbilled'] as $s => $label) {
                    $rows[] = ['Bill status: '.$label, $x['bill'][$s]['customers']];
                }

                foreach (BillingDetailsAnalytics::CAUSES as $cause => $label) {
                    $rows[] = ['Estimation cause: '.$label, $x['est'][$cause]['customers']];
                }

                foreach (['consume' => 'Consumption', 'water' => 'Water charge (GH¢)', 'rural' => 'Rural water (GH¢)', 'fire' => 'Fire fighting (GH¢)', 'sewage' => 'Sewage (GH¢)', 'service' => 'Service charge (GH¢)', 'month_total' => 'Month total (GH¢)', 'paid' => 'Paid this month (GH¢)', 'collection_ratio' => 'Collection ratio (%)', 'balance_due' => 'Balance due (GH¢)', 'owing_amount' => 'Owing (GH¢)', 'owing' => 'Owing customers', 'credit_amount' => 'Credit (GH¢)', 'credit' => 'Credit customers', 'read_0_30' => 'Last read 0 to 30 days', 'read_31_60' => 'Last read 31 to 60 days', 'read_61_90' => 'Last read 61 to 90 days', 'read_over_90' => 'Last read over 90 days', 'read_never' => 'Never read'] as $k => $label) {
                    $rows[] = [$label, $this->n($x[$k])];
                }

                $out[] = ['title' => 'Billing', 'headings' => ['Measure', 'Value'], 'rows' => $rows];
                $out[] = ['title' => 'Billing by upload', 'headings' => ['Period', 'Customers', 'Month total (GH¢)', 'Paid (GH¢)', 'Collection ratio (%)', 'Estimated bills (%)', 'Unbilled (%)', 'Balance due (GH¢)'], 'rows' => array_map(fn (array $p) => [$p['label'], $p['customers'], $p['month_total'], $p['paid'], $this->n($p['collection_ratio']), $this->n($p['estimated_share']), $this->n($p['unbilled_share']), $p['balance_due']], $b['trend'])];
            }
        }

        if ($c = $this->data($d, 'customers')) {
            if ($c['present']) {
                $rows = [['Accounts', $c['customers']], ['Accounts with a billing status', $c['billing_accounts']], ['Metered accounts', $c['metered']], ['Faulty meters', $c['faulty']], ['No meter', $c['no_meter']], ['Reachable (valid mobile)', $c['reach']['reachable']], ['Two mobile numbers', $c['reach']['two_numbers']], ['No mobile', $c['reach']['no_mobile']]];

                foreach (['by_status' => 'Status', 'by_category' => 'Service category', 'by_meter' => 'Meter status'] as $k => $label) {
                    foreach ($c[$k] as $code => $n) {
                        $rows[] = [$label.': '.($code === '' ? '(none)' : $code), $n];
                    }
                }

                $out[] = ['title' => 'Customer list', 'headings' => ['Measure', 'Value'], 'rows' => $rows];
            }
        }

        $k = $d['parts']['checks']['data'];
        $rows = [];

        if ($k['strength']) {
            $rows[] = ['Verified strength', $k['strength']['strength']];
            $rows[] = ['Metered accounts in the list', $this->n($k['strength']['metered'])];
            $rows[] = ['Customers in billing', $this->n($k['strength']['billing_customers'])];
        }

        if ($k['accounts']) {
            $rows[] = ['Accounts in billing not in the list', $this->n($k['accounts']['in_billing_not_in_list'])];
            $rows[] = ['List accounts not on this route\'s bill', $this->n($k['accounts']['in_list_not_on_this_bill'])];
        }

        $out[] = ['title' => 'Checks', 'headings' => ['Check', 'Value'], 'rows' => $rows ?: [['Nothing to check for the parts you may see.', '']]];

        return array_merge($out, $this->hiddenTable($d));
    }
}
