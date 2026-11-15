<?php

namespace App\Services\Commercial;

use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
use Illuminate\Support\Carbon;

/**
 * Turns the analytics payloads into plain, titled tables. One report array feeds the Excel workbook AND the PDF, so
 * they cannot disagree with each other, and the payloads are the ones the screens use (CommercialReportData), so they
 * cannot disagree with the screens either.
 *
 * A report is: key, title, meta (label => value, for the header), notes (the honest caveats), tables (title, headings,
 * rows) and row_count. Cells are plain numbers, strings or '' (never null). Percentages are numbers in columns whose
 * heading says (%).
 */
class CommercialExportService
{
    /**
     * What each export is, who may take it and in which formats. `permissions` are needed IN ADDITION to
     * commercial.export_reports (all of them); `any` is a list of which at least one is needed.
     */
    public const REPORTS = [
        'summary' => ['title' => 'Executive summary', 'formats' => ['excel', 'pdf'], 'permissions' => [], 'any' => ['commercial.view_dashboard', 'commercial.view_billing', 'commercial.view_reading']],
        'reading-trend' => ['title' => 'Meter reading: region trend', 'formats' => ['excel'], 'permissions' => ['commercial.view_reading'], 'any' => []],
        'readers' => ['title' => 'Meter reading: CCAs', 'formats' => ['excel', 'pdf'], 'permissions' => ['commercial.view_reader_performance'], 'any' => []],
        'billing' => ['title' => 'Billing snapshot', 'formats' => ['excel', 'pdf'], 'permissions' => ['commercial.view_billing'], 'any' => []],
        'kpis' => ['title' => 'District KPIs against targets', 'formats' => ['excel', 'pdf'], 'permissions' => ['commercial.view_billing'], 'any' => []],
        'scorecard' => ['title' => 'District scorecard', 'formats' => ['excel', 'pdf'], 'permissions' => ['commercial.view_billing', 'commercial.view_reading'], 'any' => []],
        'reading-routes' => ['title' => 'Meter reading by route and CCA', 'formats' => ['excel', 'pdf'], 'permissions' => ['commercial.view_reading'], 'any' => []],
        'billing-details' => ['title' => 'Detailed billing', 'formats' => ['excel', 'pdf'], 'permissions' => ['commercial.view_billing'], 'any' => []],
    ];

    /** The sections of the Detailed billing export (the tabs of its screen): each is an export of its own, so one stays inside the row caps. */
    public const BILLING_DETAIL_SECTIONS = [
        'all' => 'Every analysis',
        'overview' => 'B1 Bill status',
        'estimates' => 'B2 and B3 Estimates and unbilled',
        'consumption' => 'B4 and B5 Consumption and charges',
        'money' => 'B6 to B9 Money',
        'checks' => 'B10 to B12 Checks',
        'trend' => 'B13 Trend',
    ];

    // ---------------------------------------------------------------- summary (C3)

    /** @param  array<string, mixed>  $data  CommercialReportData::summary() */
    public function summary(array $data): array
    {
        $s = $data['summary'];
        $tables = [];
        $notes = ['Times shown are upload times: the reports print no run date.'];

        if (! empty($s['reading']['month'])) {
            $r = $s['reading'];
            $tables[] = ['title' => 'Meter reading, '.$r['month'].' (latest complete month)', 'headings' => ['Measure', 'Value', 'Configured target', 'Within target'], 'rows' => [
                ['Visited', $r['visited'], '', ''],
                ['Read', $r['read'], '', ''],
                ['Skip rate (%)', $this->n($r['skip_rate']), '<= '.$r['target_skip'], $this->met($r['skip_met'])],
                ['Coverage (%)', $this->n($r['coverage']), '>= '.$r['target_coverage'], $this->met($r['coverage_met'])],
            ]];
            $notes[] = 'Targets are configured placeholders until the Commercial team confirms official ones.';
        }

        if ($s['billing']) {
            $b = $s['billing'];
            $tables[] = ['title' => 'Billing, '.$b['snapshot']['label'], 'headings' => ['Measure', 'Value', 'Configured target', 'Within target'], 'rows' => [
                ['Billing for the period (GH¢)', $b['billing'], '', ''],
                ['Payments (GH¢)', $b['payments'], '', ''],
                ['Cash collection ratio (%)', $this->n($b['cash_ratio']), '>= '.$b['target_collection'], $this->met($b['collection_met'])],
                ['Estimated share of volume (%)', $this->n($b['estimation']), '', ''],
                ['Unbilled rate (%)', $this->n($b['unbilled_rate']), '', ''],
            ]];

            if ($b['not_like_for_like']) {
                $notes[] = CommercialInsightsService::notLikeForLikeNote($b['snapshot']);
            }
        }

        if ($s['exceptions']) {
            $e = $s['exceptions'];
            $tables[] = ['title' => 'Route exceptions (placeholder thresholds)', 'headings' => ['Flag', 'Routes'], 'rows' => [
                ['No activity', $e['counts']['zero_activity']],
                ['Heavy credit', $e['counts']['heavy_credit']],
                ['High unbilled', $e['counts']['high_unbilled']],
                ['High estimation', $e['counts']['high_estimation']],
                ['Routes flagged / routes in the snapshot', $e['flagged'].' / '.$e['routes']],
            ]];
        }

        $f = $s['freshness'];
        $freshRows = [];

        foreach (['reading' => 'Latest meter reading upload', 'billing' => 'Latest billing upload'] as $key => $label) {
            if ($f[$key]) {
                $freshRows[] = [$label, $f[$key]['region'].', '.$f[$key]['period'].', uploaded '.Carbon::parse($f[$key]['uploaded_at'])->format('d M Y H:i').' (batch #'.$f[$key]['batch_id'].')'];
            }
        }

        foreach ($f['overdue'] as $late) {
            $freshRows[] = ['OVERDUE: '.$late['label'].' upload, '.$late['region'], $late['days'].' days since the last upload on '.$late['last_upload']->format('d M Y').' (limit '.$late['limit'].' days)'];
        }

        $freshRows[] = ['Reading months loaded', implode(', ', $f['reading_months']) ?: 'None'];
        $freshRows[] = ['Reading months missing in between', implode(', ', $f['reading_months_missing']) ?: 'None'];

        if ($f['billing_periods'] !== null) {
            $freshRows[] = ['Billing periods loaded', implode('; ', $f['billing_periods']) ?: 'None'];
        }

        $tables[] = ['title' => 'Data freshness', 'headings' => ['Item', 'Detail'], 'rows' => $freshRows];

        $q = $s['quality'];
        $tables[] = ['title' => 'Data quality', 'headings' => ['Check', 'Count'], 'rows' => array_values(array_filter([
            $q['unmatched_readers'] !== null ? ['CCAs not in the staff directory', $q['unmatched_readers']] : null,
            $q['unresolved_districts'] !== null ? ['Billing districts not matched to a district', $q['unresolved_districts']] : null,
            $q['multi_month_snapshots'] !== null ? ['Billing snapshots that cover several months (not comparable)', $q['multi_month_snapshots']] : null,
        ]))];

        return $this->report('summary', ['Region' => $data['region_label'], 'Billing snapshot' => $data['snapshot']['label'] ?? 'None'], $notes, $tables, $data['snapshot']['period_label'] ?? null);
    }

    // ---------------------------------------------------------------- reading

    /** @param  array<string, mixed>  $data  CommercialReportData::readingTrend() */
    public function readingTrend(array $data, array $meta): array
    {
        $notes = ['Rates are worked out from the counts. The current month is in progress and still filling up.'];

        if ($data['coverage_hidden']) {
            $notes[] = 'Coverage is not shown for one district: the verified strength is region-wide.';
        }

        if ($data['small_group'] ?? false) {
            $notes[] = 'This home district has fewer than '.$data['min_readers'].' CCAs with visits in these months, so its figures are not shown without reader-level access (they would be an individual CCA\'s).';
        }

        return $this->report('reading-trend', $meta, $notes, [
            ['title' => 'Month by month', 'headings' => ['Month', 'In progress', 'Visited', 'Read', 'Skipped', 'Read rate (%)', 'Skip rate (%)', 'CCAs active', 'Verified strength', 'Coverage (%)'], 'rows' => array_map(fn (array $r) => [
                $r['label'], $r['in_progress'] ? 'Yes' : '', $r['visited'], $r['read'], $r['skipped'], $this->n($r['read_rate']), $this->n($r['skip_rate']), $r['readers'], $this->n($r['strength']), $this->n($r['coverage']),
            ], $data['trend'])],
            ['title' => 'Verified customer strength', 'headings' => ['Month', 'Verified strength', 'Change', 'Change (%)'], 'rows' => array_map(fn (array $r) => [
                $r['label'], $r['strength'], $this->n($r['change']), $this->n($r['change_pct']),
            ], $data['growth'])],
        ]);
    }

    /** @param  array<string, mixed>  $d  CommercialReportData::readers() */
    public function readers(array $d, array $meta): array
    {
        $who = fn (array $r) => [$r['staff_id'], $r['name'], $r['district'] ?? '', $r['match_status'] === 'unmatched' ? 'Not in staff directory' : ''];
        $whoHeads = ['Staff ID', 'CCA', 'Home district', 'Directory'];

        $tables = [
            ['title' => 'League table', 'headings' => [...$whoHeads, 'Visited', 'Share (%)', 'Read', 'Skipped', 'Skip rate (%)', 'Months active'], 'rows' => array_map(fn (array $r) => [
                ...$who($r), $r['visited'], $this->n($r['share']), $r['read'], $r['skipped'], $this->n($r['skip_rate']), $r['months_active'].' / '.$r['months'],
            ], $d['readers'])],
            ['title' => 'Lowest skip rates', 'headings' => [...$whoHeads, 'Visited', 'Skip rate (%)'], 'rows' => array_map(fn (array $r) => [...$who($r), $r['visited'], $this->n($r['skip_rate'])], $d['quality']['best'])],
            ['title' => 'Highest skip rates', 'headings' => [...$whoHeads, 'Visited', 'Skip rate (%)'], 'rows' => array_map(fn (array $r) => [...$who($r), $r['visited'], $this->n($r['skip_rate'])], $d['quality']['worst'])],
            ['title' => 'Consistency', 'headings' => [...$whoHeads, 'Months', 'Average visits', 'Lowest', 'Highest', 'Variation (%)', 'Months below own average'], 'rows' => array_map(fn (array $r) => [
                ...$who($r), $r['months'], $r['mean'], $r['min'], $r['max'], $this->n($r['cv']), $r['months_below_average'],
            ], $d['consistency']['rows'])],
            ['title' => 'Movement, last two complete months', 'headings' => [...$whoHeads, 'Visited before', 'Visited now', 'Change', 'Skip rate before (%)', 'Skip rate now (%)', 'Change (points)', 'Skip-rate trend'], 'rows' => array_map(fn (array $r) => [
                ...$who($r), $r['visited_before'], $r['visited_after'], $r['visited_change'], $this->n($r['skip_rate_before']), $this->n($r['skip_rate_after']), $this->n($r['skip_rate_change']), $r['trend'],
            ], $d['movement']['rows'])],
            ['title' => 'Inactive and under-used CCAs', 'headings' => ['Month', ...$whoHeads, 'Visited', 'Why'], 'rows' => $this->inactiveRows($d['inactive'], $who)],
            ['title' => 'Workload outside the normal band', 'headings' => [...$whoHeads, 'Visited', '% of median', 'Band'], 'rows' => array_map(fn (array $r) => [
                ...$who($r), $r['visited'], $this->n($r['pct_of_median']), $r['band'],
            ], array_values(array_filter($d['workload']['rows'], fn (array $r) => $r['band'] !== 'normal')))],
            ['title' => 'Skip-rate outliers', 'headings' => [...$whoHeads, 'Visited', 'Skip rate (%)', 'z-score', 'Flag'], 'rows' => array_map(fn (array $r) => [
                ...$who($r), $r['visited'], $this->n($r['skip_rate']), $this->n($r['z']), $r['flagged'] ? 'Outlier' : '',
            ], $d['outliers']['rows'])],
            ['title' => 'Scorecard (indicative)', 'headings' => [...$whoHeads, 'Visited', 'Skip rate (%)', 'Variation (%)', 'Volume rank', 'Skip rank', 'Consistency rank', 'Score'], 'rows' => array_map(fn (array $r) => [
                ...$who($r), $r['visited'], $this->n($r['skip_rate']), $this->n($r['cv']), $this->n($r['volume_pct']), $this->n($r['skip_pct']), $this->n($r['consistency_pct']), $this->n($r['score']),
            ], $d['scorecard']['rows'])],
        ];

        $w = $d['scorecard']['weights'];

        return $this->report('readers', $meta, [
            'Individual staff performance: for people who may see it, not for circulation.',
            'The district is the CCA\'s home district in the staff directory; the report has no district split.',
            'Movement, inactive, workload, consistency, outliers and the scorecard use complete months only; the current month is in progress.',
            'The scorecard is indicative (weights: volume '.($w['volume'] * 100).'%, skip rate '.($w['skip'] * 100).'%, consistency '.($w['consistency'] * 100).'%); it starts conversations, it is not a verdict.',
        ], $tables);
    }

    // ---------------------------------------------------------------- billing

    /** @param  array<string, mixed>  $d  CommercialReportData::billingSnapshot() */
    public function billing(array $d): array
    {
        $o = $d['overview'];
        if ($d['snapshot']['is_range'] ?? false) {
            $notes = ['A date range: the uploads inside it that do not overlap are added up (flows are summed; the closing balance and customer counts are the latest upload\'s). Ratios are worked out from the sums, not averaged over routes.', ...($d['range_notes'] ?? [])];
        } else {
            $notes = ['One snapshot only: snapshots are never added together. Ratios are worked out from the sums, not averaged over routes.'];

            if (! $d['snapshot']['is_single_month']) {
                $notes[] = 'This is a multi-month period: it overlaps single months and cannot be compared or trended.';
            }
        }

        $notes[] = 'A negative balance is a customer credit (confirmed by the Commercial team).';

        $tables = [
            ['title' => 'Overview', 'headings' => ['District', 'Routes', 'Billing (GH¢)', 'Share (%)', 'Volume (000 litres)', 'Customers billed', 'GH¢ per customer', 'GH¢ per m3'], 'rows' => [
                ...array_map(fn (array $r) => [$r['district'], $r['routes'], $r['billing'], $this->n($r['share']), $r['volume'], $r['billed'], $this->n($r['per_customer']), $this->n($r['per_m3'])], $o['districts']),
                ['All shown', $o['totals']['routes'], $o['totals']['billing_for_period'], '', $o['totals']['volume_total'], (int) $o['totals']['billed_total'], $this->n($o['totals']['per_customer']), $this->n($o['totals']['per_m3'])],
            ]],
            ['title' => 'Routes', 'headings' => ['Rank', 'Route', 'District', 'Billing (GH¢)', 'Share (%)', 'Volume (000 litres)', 'Customers billed', 'GH¢ per customer', 'GH¢ per m3'], 'rows' => array_map(fn (array $r) => [
                $r['rank'], $r['route'], $r['district'], $r['billing'], $this->n($r['share']), $r['volume'], $r['billed'], $this->n($r['per_customer']), $this->n($r['per_m3']),
            ], $o['routes'])],
            ['title' => 'Balance roll-forward', 'headings' => ['District', 'Opening (GH¢)', 'Billing', 'Adjustments', 'Receivable', 'Payments', 'Closing', 'Adds up'], 'rows' => array_map(fn (array $r) => [
                $r['key'] === '' ? 'All shown' : $r['district'], $r['opening'], $r['billing'], $r['adjustment'], $r['receivable'], $r['payments'], $r['closing'], $r['balances'] ? 'Yes' : 'No',
            ], [...$d['roll']['districts'], $d['roll']['total']])],
            ['title' => 'Collections', 'headings' => ['District', 'Billing (GH¢)', 'Payments (GH¢)', 'This period', 'Earlier months', 'Offsets', 'Cash collection ratio (%)', 'Against this period (%)', 'Amount due (GH¢)', 'Collected of amount due (%)', 'Arrears share of cash (%)'], 'rows' => array_map(fn (array $r) => [
                $r['key'] === '' ? 'All shown' : $r['district'], $r['billing'], $r['payments'], $r['payment_for_month'], $r['prev_month_payment'], $r['offset_payments'], $this->n($r['cash_ratio']), $this->n($r['current_ratio']), $r['receivable'], $this->n($r['due_ratio']), $this->n($r['prior_share']),
            ], [...$d['collections']['districts'], $d['collections']['total']])],
            ['title' => 'Balances (credits)', 'headings' => ['District', 'Routes', 'Routes with credit', 'Credit (GH¢)', 'Net closing (GH¢)'], 'rows' => array_map(fn (array $r) => [
                $r['key'] === '' ? 'All shown' : $r['district'], $r['routes'], $r['credit_routes'], $r['credit_amount'], $r['closing'],
            ], [...$d['balances']['districts'], $d['balances']['total']])],
            ['title' => 'Estimation and unbilled', 'headings' => ['District', 'Billed', 'Unbilled', 'Unbilled rate (%)', 'Estimated volume (%)', 'Estimated bills (%)', 'Average, metered', 'Average, unmetered', 'Actual reading'], 'rows' => array_map(fn (array $r) => [
                $r['key'] === '' ? 'All shown' : $r['district'], $r['billed'], $r['unbilled'], $this->n($r['unbilled_rate']), $this->n($r['estimation_volume']), $this->n($r['estimation_count']), $r['avg_metered'], $r['avg_unmetered'], $r['actual'],
            ], [...$d['estimation']['districts'], $d['estimation']['total']])],
            ['title' => 'Consumption bands', 'headings' => ['Band (000 litres)', 'Customers', 'Customers (%)', 'Volume', 'Volume (%)', 'Amount (GH¢)', 'Amount (%)', 'GH¢ per m3', 'GH¢ per customer'], 'rows' => $d['bands']['has_bands'] ? array_map(fn (array $r) => [
                $r['band'], $r['customers'], $this->n($r['customer_share']), $r['volume'], $this->n($r['volume_share']), $r['amount'], $this->n($r['amount_share']), $this->n($r['per_m3']), $this->n($r['per_customer']),
            ], $d['bands']['rows']) : []],
            ['title' => 'Exceptions', 'headings' => ['Route', 'District', 'Flags', 'Billed', 'Unbilled', 'Unbilled rate (%)', 'Estimated bills (%)', 'Closing (GH¢)'], 'rows' => array_map(fn (array $r) => [
                $r['route'], $r['district'], implode(', ', array_map(fn ($flag) => str_replace('_', ' ', $flag), $r['flags'])), $r['billed'], $r['unbilled'], $this->n($r['unbilled_rate']), $this->n($r['estimation_rate']), $r['closing'],
            ], $d['exceptions']['rows'])],
        ];

        $th = $d['exceptions']['thresholds'];
        $notes[] = "Exception thresholds are placeholders: at least {$th['min_customers']} customers behind a percentage, unbilled above {$th['high_unbilled_pct']}%, estimated bills above {$th['high_estimation_pct']}%, credit of GH¢ {$th['credit_amount']} or more.";

        return $this->report('billing', [
            'Region' => $d['snapshot']['region'],
            'Snapshot' => $d['snapshot']['label'],
            'Period' => $d['snapshot']['period_label'],
            'District' => $d['district'] !== '' ? $d['district'] : 'All districts',
        ], $notes, $tables, $d['snapshot']['period_label']);
    }

    // ---------------------------------------------------------------- KPIs against targets

    /** @param  array<string, mixed>  $d  CommercialReportData::kpis() */
    public function kpis(array $d): array
    {
        $months = $d['months'];
        $isPart = fn (array $m) => ($m['full'] ?? true) === false;
        $labels = array_map(fn (array $m) => $m['label'].' '.$m['year'].($isPart($m) ? ' ('.$m['part_label'].')' : ''), $months);
        $short = array_map(fn (array $m) => $m['short'], $months);
        $notes = [
            'Sales is the volume billed (thousand litres, which is 1 m3 per thousand); billing is "billing for the period"; collection is total payments. Targets are yearly: a month\'s target is the annual target ÷ 12, and efficiency = actual ÷ that target × 100, left blank where the year has no target. Year to date compares the months uploaded so far this year with the annual target × those months ÷ 12.',
            $d['arrears_note'],
            $d['age_note'],
            'Each month comes from the single-month billing snapshot of that month (the newest non-voided upload); snapshots are never added together.',
        ];

        // step 0.1: a month made of several uploads, and a part month (said only when there is one, so a normal export reads as before)
        foreach ($months as $m) {
            if ($isPart($m)) {
                $notes[] = $m['label'].' '.$m['year'].' is a PART MONTH: it has '.$m['part_label'].' ('.$m['missing'].' not uploaded). Its figures are what was uploaded so far; its efficiency against the monthly target is not shown, it is left out of the year to date and it is never compared with another month.';
            } elseif (count($m['batch_ids'] ?? []) > 1) {
                $notes[] = $m['label'].' '.$m['year'].' is a full month made of '.count($m['batch_ids']).' uploads that do not overlap: their flows are added up and the closing balance is the latest upload\'s.';
            }
        }

        $tables = [];

        foreach (array_filter([$d['total'], ...$d['districts']]) as $block) {
            $tables[] = [
                'title' => 'KPIs: '.($block['id'] === null && $block['name'] === 'All districts' ? 'all districts' : $block['name']),
                'headings' => ['KPI', 'Annual target', 'Monthly target (annual ÷ 12)', ...$labels, ...array_map(fn ($s) => 'Efficiency '.$s.' (%)', $short), 'Year to date', 'Year-to-date efficiency (%)', 'Of the annual target (%)'],
                'rows' => array_map(fn (array $row) => [
                    $row['label'].' ('.$row['unit'].')', $this->n($row['annual']), $this->n($row['target']),
                    ...array_map(fn (array $m) => $this->n($row['actuals'][$m['key']] ?? null), $months),
                    ...array_map(fn (array $m) => $this->n($row['efficiency'][$m['key']] ?? null), $months),
                    $this->n($row['ytd']['actual']), $this->n($row['ytd']['efficiency']), $this->n($row['ytd']['progress']),
                ], $block['rows']),
            ];
            $tables[] = [
                'title' => 'Arrears: '.($block['id'] === null && $block['name'] === 'All districts' ? 'all districts' : $block['name']),
                'headings' => ['Month', 'Arrears: owed (GH¢)', 'Age of arrears (months)', 'Customer credits (GH¢)', 'Net balance (GH¢)', 'Change in arrears (GH¢)', 'Source'],
                'rows' => array_map(fn (array $a) => [$a['label'], $a['arrears'], $this->n($a['age']), $a['credit'], $a['net'], $this->n($a['change']), ['customers' => 'Customer list', 'routes' => 'Billing routes', 'mixed' => 'Both'][$a['source']] ?? $a['source']], $block['arrears']),
            ];
        }

        $first = $months[0] ?? null;
        $last = $months[count($months) - 1] ?? null;
        $period = $first && $last ? ($first['key'] === $last['key'] ? $labels[0] : $labels[0].' to '.end($labels)) : 'no months';

        return $this->report('kpis', [
            'Region' => (string) $d['region'],
            'Customer segment' => (string) ($d['segments'][$d['segment']]['label'] ?? 'All customers'),
            'Months' => $period,
            'District' => ($d['district_id'] ?? null) ? (string) ($d['districts'][0]['name'] ?? '') : ($d['total'] === null ? 'Your district' : 'All districts'),
        ], $notes, $tables, $period);
    }

    // ---------------------------------------------------------------- scorecard (C1)

    /** @param  array<string, mixed>  $d  CommercialReportData::scorecard() */
    public function scorecard(array $d): array
    {
        $c = $d['scorecard'];
        $snapshot = $d['snapshot'];
        $notes = [
            'Reading is grouped by the CCA\'s HOME district in the staff directory: an approximation, as the reading report has no district split.',
            'Reading figures are the complete months inside the billing period'.($c['reading_months'] === [] ? ': there are none yet.' : ': '.implode(', ', $c['reading_months']).'.'),
            'Coverage is not shown per district: the verified strength is region-wide.',
        ];

        if ($c['segment_note']) {
            array_unshift($notes, CommercialInsightsService::notLikeForLikeNote($snapshot));
        }

        if ($c['hide_small_groups']) {
            $notes[] = 'A district\'s reading figures are left out where fewer than '.$c['min_readers'].' CCAs had visits: they would be one person\'s own figures.';
        }

        return $this->report('scorecard', ['Region' => $snapshot['region'], 'Billing snapshot' => $snapshot['label']], $notes, [
            ['title' => 'District scorecard', 'headings' => ['District', 'Billing (GH¢)', 'Payments (GH¢)', 'Cash collection ratio (%)', 'Estimated volume (%)', 'Unbilled rate (%)', 'Visits', 'Reads', 'Skip rate (%)', 'Active CCAs', 'Visits per CCA', 'Reading figures'], 'rows' => array_map(fn (array $r) => [
                $r['district'], $this->n($r['billing']), $this->n($r['payments']), $this->n($r['cash_ratio']), $this->n($r['estimation']), $this->n($r['unbilled_rate']),
                $this->n($r['visits']), $this->n($r['read']), $this->n($r['skip_rate']), $this->n($r['active_readers']), $this->n($r['visits_per_reader']),
                $r['reading_hidden'] ? 'Fewer than '.$c['min_readers'].' CCAs' : '',
            ], $c['rows'])],
        ], $snapshot['period_label']);
    }

    // ---------------------------------------------------------------- reading by route and CCA (Phase 8c)

    /**
     * The reading report BY ROUTE AND CCA (analyses R1 to R8 and the trend by upload). Built from the same data as the screen; a viewer
     * without commercial.view_reader_performance gets districts and counts but no named CCA, exactly as on the screen.
     *
     * @param  array<string, mixed>  $d  CommercialReportData::routeReading()
     */
    public function readingRoutes(array $d): array
    {
        $a = $d['analysis'];
        $named = (bool) $d['named'];
        $meta = [
            'Region' => (string) ($d['region'] ?? 'All'),
            'Period' => (string) ($d['range']['label'] ?? 'no upload'),
            'District' => $d['district_id'] ? (string) ($d['districts'][$d['district_id']] ?? '') : ($d['district_limited'] ? 'Your district' : 'All districts'),
        ];
        $notes = [
            'Rates are worked out from the counts: skip rate = skipped / visited, coverage = visited / verified strength. The report\'s own percentages and its Unvisited column are never used.',
            'A route\'s verified strength is counted once, for the CCA who owns the route (the most visits); the other CCAs listed on it are "assigned, no visits".',
            ...$d['notes'],
        ];

        if ($d['range']) {
            $notes[] = 'Uploads used: '.implode('; ', array_map(fn (array $b) => 'batch #'.$b['id'].' ('.$b['label'].')', $d['range']['batches'])).'. Times of upload, not run dates: the report prints no run date.';
        }

        if (! $named) {
            $notes[] = 'No CCA is named here: individual CCA figures need the CCA performance permission. Districts and counts are shown.';
        }

        if ($d['small_group'] || $d['held_back'] !== []) {
            $notes[] = 'A district with fewer than '.$d['min_readers'].' CCAs with visits has its figures held back ('.($d['small_group'] ? 'this is your district' : implode(', ', $d['held_back'])).'): they would be an individual CCA\'s own.';
        }

        if ($a === null) {
            return $this->report('reading-routes', $meta, $notes, [['title' => 'Held back', 'headings' => ['Note'], 'rows' => [['Your district has too few CCAs with visits for these figures to be shown.']]]], $meta['Period']);
        }

        $t = $a['totals'];
        $tables = [
            ['title' => 'Summary', 'headings' => ['Measure', 'Value'], 'rows' => [
                ['Routes', $d['counts']['routes']], ['CCAs listed', $d['counts']['ccas']], ['CCAs with routes', $d['counts']['ccas_with_routes']],
                ['Verified strength', $t['strength']], ['Read', $t['read']], ['Skipped', $t['skipped']], ['Visited', $t['visited']],
                ['Unvisited (strength - visited)', $t['unvisited']], ['Coverage (%)', $this->n($t['coverage'])], ['Skip rate (%)', $this->n($t['skip_rate'])],
            ]],
            ['title' => 'R3 Districts', 'headings' => ['District', 'Routes', 'CCAs owning routes', 'CCAs with visits', 'Verified strength', 'Read', 'Skipped', 'Visited', 'Unvisited', 'Coverage (%)', 'Skip rate (%)', 'Figures'], 'rows' => array_map(fn (array $r) => [
                $r['name'], $r['routes'], $r['ccas'], $r['active_ccas'], $r['strength'], $this->n($r['read']), $this->n($r['skipped']), $this->n($r['visited']), $this->n($r['unvisited']), $this->n($r['coverage']), $this->n($r['skip_rate']),
                ($r['held_back'] ?? false) ? 'Held back: fewer than '.$d['min_readers'].' CCAs' : '',
            ], $a['districts'])],
        ];

        if ($named) {
            $tables[] = ['title' => 'R1 CCA scorecard', 'headings' => ['Rank', 'Staff ID', 'CCA', 'District', 'Routes owned', 'Strength owned', 'Read', 'Skipped', 'Visited', 'Unvisited', 'Coverage (%)', 'Skip rate (%)', 'Note'], 'rows' => array_map(fn (array $c) => [
                $this->n($c['rank']), $c['staff_id'], (string) $c['name'], (string) $c['district'], $c['owned_routes'], $c['owned_strength'], $c['read'], $c['skipped'], $c['visited'], $c['unvisited'], $this->n($c['coverage']), $this->n($c['skip_rate']),
                $c['no_routes'] ? 'Listed with no route' : ($c['zero_visits'] ? 'Routes, no visits' : (! $c['eligible'] ? 'Fewer than '.$a['min_visits'].' visits: not ranked' : '')),
            ], array_values($a['ccas']))];
        }

        $routeRows = collect($a['routes'])->filter(fn (array $r) => $r['placed'])->sortBy([['skip_rate', 'desc'], ['visited', 'desc'], ['label', 'asc']])->values();
        $tables[] = ['title' => 'R2 Route league table', 'headings' => ['Route', 'District', 'Owner CCA', 'Verified strength', 'Read', 'Skipped', 'Visited', 'Unvisited', 'Coverage (%)', 'Skip rate (%)'], 'rows' => $routeRows->map(fn (array $r) => [
            (string) $r['label'], (string) $r['district'], $named ? trim($r['owner_cca'].' '.$r['owner_name']) : '', $r['strength'], $r['read'], $r['skipped'], $r['visited'], $r['unvisited'], $this->n($r['coverage']), $this->n($r['skip_rate']),
        ])->all()];

        $w = $a['workload'];
        $tables[] = ['title' => 'R4 Workload balance', 'headings' => ['Measure', 'Value'], 'rows' => [
            ['CCAs owning routes', $w['ccas']], ['Routes owned', $w['routes']], ['Largest strength owned', $this->n($w['largest'])], ['Median strength owned', $this->n($w['median'])],
            ['Median routes owned', $this->n($w['median_routes'])], ['Coefficient of variation (%)', $this->n($w['cv'])],
            ['CCAs above '.$w['high_pct'].'% of the median', $d['counts']['workload_high']], ['CCAs below '.$w['low_pct'].'% of the median', $d['counts']['workload_low']],
        ]];

        if ($named) {
            $tables[] = ['title' => 'R4 CCAs off the median', 'headings' => ['Staff ID', 'CCA', 'Routes owned', 'Strength owned', '% of the median', 'Band'], 'rows' => array_map(fn (array $r) => [
                $r['staff_id'], (string) $r['name'], $r['owned_routes'], $r['owned_strength'], $this->n($r['pct_of_median']), $r['band'] === 'high' ? 'Above' : 'Below',
            ], array_merge($w['high'], $w['low']))];
        }

        $tables[] = ['title' => 'R5 Unvisited by route', 'headings' => ['Route', 'District', 'Verified strength', 'Visited', 'Unvisited', 'Coverage (%)'], 'rows' => array_map(fn (array $r) => [
            (string) $r['label'], (string) $r['district'], $r['strength'], $r['visited'], $r['unvisited'], $this->n($r['coverage']),
        ], array_slice($a['backlog']['routes'], 0, ReadingRouteAnalyticsService::LIST_CAP))];
        $tables[] = ['title' => 'R5 Routes with no visits', 'headings' => ['Route', 'District', 'Verified strength'], 'rows' => array_map(fn (array $r) => [(string) $r['label'], (string) $r['district'], $r['strength']], $a['backlog']['routes_zero_visits'])];

        if ($named) {
            $tables[] = ['title' => 'R5 Unvisited by CCA', 'headings' => ['Staff ID', 'CCA', 'Strength owned', 'Visited', 'Unvisited'], 'rows' => array_map(fn (array $c) => [$c['staff_id'], (string) $c['name'], $c['owned_strength'], $c['visited_owned'], $c['unvisited']], $a['backlog']['ccas'])];
            $tables[] = ['title' => 'R5 CCAs with no visits', 'headings' => ['Staff ID', 'CCA', 'Routes owned', 'Strength owned'], 'rows' => array_map(fn (array $c) => [$c['staff_id'], (string) $c['name'], $c['owned_routes'], $c['owned_strength']], $a['backlog']['ccas_zero_visits'])];
            $tables[] = ['title' => 'R6 CCAs with no route', 'headings' => ['Staff ID', 'CCA'], 'rows' => array_map(fn (array $c) => [$c['staff_id'], (string) $c['name']], $a['coverage']['ccas_no_routes'])];
        } else {
            $tables[] = ['title' => 'R6 CCA counts', 'headings' => ['Measure', 'CCAs'], 'rows' => [['Listed with no route', $d['counts']['ccas_no_routes']], ['Routes but no visits', $d['counts']['ccas_zero_visits']]]];
        }

        $tables[] = ['title' => 'R6 Routes with several CCAs', 'headings' => ['Route', 'District', 'Owner CCA', 'CCAs on the route', 'Verified strength (counted once)'], 'rows' => array_map(fn (array $r) => [
            (string) $r['label'], (string) $r['district'], $named ? trim($r['owner_cca'].' '.$r['owner_name']) : '', count($r['multi_rows'] ?? []) ?: ($named ? 0 : ''), $r['strength'],
        ], $a['coverage']['multi_cca_routes'])];

        if ($d['unread_routes']) {
            $tables[] = ['title' => 'R6 Routes with no reading row', 'headings' => ['Route', 'District', 'First filed by'], 'rows' => array_map(fn (array $r) => [(string) $r['label'], (string) $r['district'], (string) $r['source']], $d['unread_routes']['shown'])];

            if ($d['unread_routes']['capped']) {
                $notes[] = 'Only the first '.count($d['unread_routes']['shown']).' of '.$d['unread_routes']['total'].' routes with no reading row are listed.';
            }
        }

        $o = $a['outliers'];
        $tables[] = ['title' => 'R7 Outliers by district', 'headings' => ['District', 'CCAs with '.$o['min_visits'].'+ visits', 'Enough to judge (at least '.$o['min_peers'].')', 'Mean skip rate (%)', 'Standard deviation', 'Outlier above (%)', 'Outliers'], 'rows' => array_map(fn (array $r) => [
            $r['name'], $r['eligible'], $r['enough'] ? 'Yes' : 'No: too few', $r['mean'], $r['stdev'], $this->n($r['threshold']), $r['flagged'],
        ], $o['districts'])];

        if ($named && $o['flagged'] !== []) {
            $tables[] = ['title' => 'R7 CCAs above their district', 'headings' => ['Staff ID', 'CCA', 'District', 'Visited', 'Skip rate (%)', 'District mean (%)', 'Standard deviations above'], 'rows' => array_map(fn (array $c) => [
                $c['staff_id'], (string) $c['name'], (string) $c['district'], $c['visited'], $this->n($c['skip_rate']), $c['district_mean'], $c['z'],
            ], $o['flagged'])];
        }

        $s = $d['strength'];

        if ($s && ($s['available'] ?? false)) {
            $tables[] = ['title' => 'R8 Strength vs customers', 'headings' => ['Measure', 'Value'], 'rows' => [
                ['Routes compared', $s['compared']], ['Median difference, any direction (%)', $this->n($s['median_abs_pct'])], ['Median difference, signed (%)', $this->n($s['median_signed_pct'])],
                ['Routes beyond '.$s['threshold'].'%', count($s['beyond'])], ['Routes with an ambiguous code (left out)', count($s['ambiguous'])], ['Routes with no metered accounts on the customer list', $s['no_match']],
            ]];
            $tables[] = ['title' => 'R8 Beyond the threshold', 'headings' => ['Route', 'District', 'Verified strength', 'Metered accounts', 'Difference', 'Difference (%)'], 'rows' => array_map(fn (array $r) => [(string) $r['label'], (string) $r['district'], $r['strength'], $r['metered'], $r['difference'], $r['relative_pct']], $s['beyond'])];
            $tables[] = ['title' => 'R8 Ambiguous codes', 'headings' => ['Route', 'District', 'Verified strength'], 'rows' => array_map(fn (array $r) => [(string) $r['label'], (string) $r['district'], $r['strength']], $s['ambiguous'])];
            $notes[] = 'Metered accounts are the accounts with a working or faulty meter in the newest customer list ('.($s['as_of'] ?? 'no date').').';
        }

        $tables[] = ['title' => 'Trend by upload', 'headings' => ['Period', 'Routes', 'CCAs with visits', 'Verified strength', 'Read', 'Skipped', 'Visited', 'Unvisited', 'Coverage (%)', 'Skip rate (%)'], 'rows' => array_map(fn (array $p) => [
            $p['label'], $p['routes'], $p['ccas_active'], $p['strength'], $p['read'], $p['skipped'], $p['visited'], $p['unvisited'], $this->n($p['coverage']), $this->n($p['skip_rate']),
        ], $d['trend'])];

        return $this->report('reading-routes', $meta, $notes, $tables, $meta['Period']);
    }

    // ---------------------------------------------------------------- detailed billing (B1 to B13)

    /**
     * The Detailed Billing Report analyses of the Billing details screen, one section at a time ('all' for every table). Built from the same
     * CommercialReportData::billingDetails() call as the page, so the file and the screen cannot disagree. The lists hold account numbers only.
     *
     * @param  array<string, mixed>  $d  CommercialReportData::billingDetails()
     */
    public function billingDetails(array $d, string $section = 'all'): array
    {
        $section = array_key_exists($section, self::BILLING_DETAIL_SECTIONS) ? $section : 'all';
        $a = $d['analysis'];
        $named = (bool) $d['named'] && (bool) $d['cca_source'];
        $meta = [
            'Region' => (string) ($d['region'] ?? 'All'),
            'Period' => (string) ($d['range']['label'] ?? 'no upload'),
            'District' => $d['district_id'] ? (string) ($d['districts'][$d['district_id']] ?? '') : ($d['district_limited'] ? 'Your district' : 'All districts'),
            'Category' => $d['category'] ? (string) ($d['categories'][$d['category']] ?? $d['category']) : 'All',
            'Section' => self::BILLING_DETAIL_SECTIONS[$section],
        ];

        if ($d['named']) {
            $meta['CCA'] = $d['cca'] ? (string) ($d['ccas'][$d['cca']] ?? $d['cca']) : 'All';
        }

        if ($d['route_id']) {
            $meta['Route'] = (string) ($d['routes_options'][$d['route_id']] ?? '');
        }

        $notes = [
            'Money is in GH¢. Ratios are worked out from the sums, never averaged; they are blank when the denominator is zero. A negative balance is a customer credit and is never netted off what customers owe.',
            'ESTIMATED and ESTIMATE are both estimated bills: the meter is faulty or missing, or a working meter was not read. The cause comes from the meter status, never from the spelling.',
            ...$d['notes'],
        ];

        if ($d['range']) {
            $notes[] = 'Uploads used: '.implode('; ', array_map(fn (array $b) => 'batch #'.$b['id'].' ('.$b['label'].')', $d['range']['batches'])).'. Times of upload, not run dates.';
        }

        if ($d['named'] && $d['cca_source']) {
            $notes[] = 'The CCA beside a route is its owner in the reading report by route of '.$d['cca_source']['label'].' (batch #'.$d['cca_source']['batch_id'].').';
        } elseif (! $d['named']) {
            $notes[] = 'No CCA is named here: individual CCA figures need the CCA performance permission.';
        }

        if ($a === null || $a['totals']['customers'] === 0) {
            return $this->report('billing-details', $meta, $notes, [['title' => 'Nothing to show', 'headings' => ['Note'], 'rows' => [['No upload lies inside this range, or the filters leave no customers.']]]], $meta['Period']);
        }

        $t = $a['totals'];
        $n = fn ($v) => $this->n($v);
        $routeHead = fn (array $heads) => ['Route', 'District', ...($named ? ['CCA'] : []), ...$heads];
        $routeCells = fn (array $r, array $cells) => [(string) $r['label'], (string) $r['district'], ...($named ? [(string) ($r['cca_staff_id'] ? trim($r['cca_staff_id'].' '.$r['cca_name']) : '')] : []), ...$cells];
        $list = function (string $kind, string $title) use ($d, $named): array {
            $l = $d['lists'][$kind];

            return ['title' => $title, 'headings' => ['Account', 'Route', 'District', ...($named ? ['CCA'] : []), $l['amount_label']], 'rows' => array_map(fn (array $r) => [(string) $r['account'], $r['route'], (string) ($r['district'] ?? ''), ...($named ? [(string) ($r['cca'] ?? '')] : []), $r['amount']], array_slice($l['rows'], 0, max(1, (int) config('gwl.commercial_billing_detail_issue_cap', 200))))];
        };
        $byGroup = function (string $title, string $firstHead, array $rows, string $nameKey, array $heads, callable $cells): array {
            return ['title' => $title, 'headings' => [$firstHead, ...$heads], 'rows' => array_map(fn (array $r) => [(string) $r[$nameKey], ...$cells($r)], $rows)];
        };
        $byRoute = fn (string $title, array $rows, array $heads, callable $cells): array => ['title' => $title, 'headings' => $routeHead($heads), 'rows' => array_map(fn (array $r) => $routeCells($r, $cells($r)), $rows)];

        $statusHeads = ['Customers', 'Regular', 'Estimated', 'Estimate', 'Unbilled', 'Month total (GH¢)', 'Estimated share (%)', 'Unbilled share (%)'];
        $statusCells = fn (array $r) => [$r['customers'], $r['bill']['REGULAR']['customers'], $r['bill']['ESTIMATED']['customers'], $r['bill']['ESTIMATE']['customers'], $r['bill']['UNBILLED']['customers'], $r['month_total'], $n($r['estimated_share']), $n($r['unbilled_share'])];
        $causeHeads = ['Estimated bills', 'Faulty meter (F)', 'No meter (N or blank)', 'Working meter not read (W)', 'Other meter status', 'Billed value (GH¢)', 'Faulty value', 'No meter value', 'Working, not read value'];
        $causeCells = fn (array $r) => [$r['estimated_customers'], $r['est']['F']['customers'], $r['est']['N']['customers'], $r['est']['W']['customers'], $r['est']['O']['customers'], $r['estimated_value'], $r['est']['F']['month_total'], $r['est']['N']['month_total'], $r['est']['W']['month_total']];
        $consHeads = ['Billed customers', 'Consumption', 'Per bill', 'Zero-consumption regular bills', 'Share of regular bills (%)'];
        $consCells = fn (array $r) => [$r['billed_customers'], $r['consume'], $n($r['consume_per_bill']), $r['zero_regular'], $n($r['zero_regular_share'])];
        $chargeHeads = ['Water', 'Rural water', 'Fire fighting', 'Sewage', 'Service charge', 'Month total', 'Water share (%)', 'Rural share (%)', 'Fire share (%)', 'Sewage share (%)', 'Service share (%)'];
        $chargeCells = fn (array $r) => [$r['water'], $r['rural'], $r['fire'], $r['sewage'], $r['service'], $r['month_total'], $n($r['component_share']['water']), $n($r['component_share']['rural']), $n($r['component_share']['fire']), $n($r['component_share']['sewage']), $n($r['component_share']['service'])];
        $colHeads = ['Month total', 'Paid this month', 'Previous-month payment', 'Offset payments', 'Collection ratio (%)', 'Amount due', 'Collected of the amount due (%)'];
        $colCells = fn (array $r) => [$r['month_total'], $r['paid'], $r['prev_payment'], $r['offset'], $n($r['collection_ratio']), $r['amount_due'], $n($r['collected_of_due'])];
        $recHeads = ['Previous balance', 'Adjusted', 'Balance due', 'Owing customers', 'Owing (GH¢)', 'Credit customers', 'Credit (GH¢)'];
        $recCells = fn (array $r) => [$r['previous_balance'], $r['adjusted'], $r['balance_due'], $r['owing'], $r['owing_amount'], $r['credit'], $r['credit_amount']];
        $tariffHeads = ['Bills tested', 'Mismatches', 'Mismatch share (%)'];
        $tariffCells = fn (array $r) => [$r['tariff_checked'], $r['tariff_mismatch'], $n($r['tariff_mismatch_pct'])];
        $recencyHeads = ['Customers', '0 to 30 days', '31 to 60 days', '61 to 90 days', 'Over 90 days', 'Never read', 'Over 90 days or never (%)'];
        $recencyCells = fn (array $r) => [$r['customers'], $r['read_0_30'], $r['read_31_60'], $r['read_61_90'], $r['read_over_90'], $r['read_never'], $n($r['read_over_90_share'])];

        $sections = [];

        $sections['overview'] = [
            ['title' => 'Summary', 'headings' => ['Measure', 'Value'], 'rows' => [
                ['Routes', $d['counts']['routes']], ['Customers', $t['customers']], ['Billed customers', $t['billed_customers']], ['Month total (GH¢)', $t['month_total']], ['Paid this month (GH¢)', $t['paid']],
                ['Collection ratio (%)', $n($t['collection_ratio'])], ['Estimated bills', $t['estimated_customers']], ['Estimated share of the billed (%)', $n($t['estimated_share'])], ['Unbilled', $t['unbilled']],
                ['Balance due (GH¢)', $t['balance_due']], ['Owing (GH¢)', $t['owing_amount']], ['Credit (GH¢)', $t['credit_amount']],
            ]],
            $byGroup('B1 Status by district', 'District', $a['districts'], 'name', $statusHeads, $statusCells),
            $byGroup('B1 Status by category', 'Category', $a['categories'], 'name', $statusHeads, $statusCells),
            $byRoute('B1 Status by route', $a['routes'], $statusHeads, $statusCells),
        ];

        $sections['estimates'] = [
            $byGroup('B2 Cause by district', 'District', $a['districts'], 'name', $causeHeads, $causeCells),
            $byRoute('B2 Cause by route', array_values(array_filter($a['routes'], fn (array $r) => $r['estimated_customers'] > 0)), $causeHeads, $causeCells),
            ['title' => 'B3 Unbilled by status', 'headings' => ['Account status', 'Active billing status', 'Unbilled customers', 'By meter status'], 'rows' => array_map(fn (array $u) => [$u['status'] === '' ? '(blank)' : $u['status'], $u['status'] === 'HOLD' ? 'Billing paused (HOLD)' : ($u['active_billing'] ? 'Yes' : 'No'), $u['customers'], collect($u['by_meter'])->map(fn ($c, $m) => ($m === '' ? '(blank)' : $m).' '.$c)->implode(', ')], $a['unbilled'])],
            $list('active_unbilled', 'B3 Active unbilled accounts'),
            $list('billed_not_active', 'B3 Billed, not active'),
        ];

        $sections['consumption'] = [
            $byGroup('B4 Consumption by district', 'District', $a['districts'], 'name', $consHeads, $consCells),
            $byGroup('B4 Consumption by category', 'Category', $a['categories'], 'name', $consHeads, $consCells),
            $byRoute('B4 Consumption by route', $a['routes'], $consHeads, $consCells),
            ['title' => 'B4 Consumption bands', 'headings' => ['Band', 'Customers', 'Share (%)', 'Consumption'], 'rows' => array_map(fn (array $b) => [$b['band'], $b['customers'], $n($b['share']), $b['consume']], $a['bands'])],
            $list('zero_consume_regular', 'B4 Zero-consumption regular'),
            $byGroup('B5 Charges by district', 'District', $a['districts'], 'name', $chargeHeads, $chargeCells),
            $byGroup('B5 Charges by category', 'Category', $a['categories'], 'name', $chargeHeads, $chargeCells),
        ];

        $con = $d['concentration'];
        $sections['money'] = [
            $byGroup('B6 Collections by district', 'District', $a['districts'], 'name', $colHeads, $colCells),
            $byGroup('B6 Collections by category', 'Category', $a['categories'], 'name', $colHeads, $colCells),
            $byRoute('B6 Collections by route', $a['routes'], $colHeads, $colCells),
            $byGroup('B7 Receivables by district', 'District', $a['districts'], 'name', $recHeads, $recCells),
            $byGroup('B7 Receivables by category', 'Category', $a['categories'], 'name', $recHeads, $recCells),
            ['title' => 'B7 Top 10 percent of owing', 'headings' => ['Measure', 'Value'], 'rows' => $con['available']
                ? [['Owing accounts', $con['owing']], ['Owed balance (GH¢)', $con['amount']], ['Accounts in the top 10 percent', $con['top_n']], ['Held by them (GH¢)', $con['top_amount']], ['Share of the owed balance (%)', $n($con['share'])]]
                : [['Not available', $con['reason']]]],
            $list('top_debtor', 'B7 Top debtors'),
            $byGroup('B8 Adjustments by category', 'Category', $a['categories'], 'name', ['Adjusted customers', 'Adjusted (GH¢)'], fn (array $r) => [$r['adjusted_count'], $r['adjusted']]),
            $byRoute('B8 Adjustments by route', array_values(array_filter($a['routes'], fn (array $r) => $r['adjusted_count'] > 0)), ['Adjusted customers', 'Adjusted (GH¢)'], fn (array $r) => [$r['adjusted_count'], $r['adjusted']]),
            $list('large_adjustment', 'B8 Largest adjustments'),
            $byRoute('B9 Billed not paid by route', array_values(array_filter($a['routes'], fn (array $r) => $r['billed_unpaid'] > 0)), ['Billed, not paid', 'Value (GH¢)', 'Share of the billed (%)'], fn (array $r) => [$r['billed_unpaid'], $r['billed_unpaid_amount'], $n($r['billed_unpaid_share'])]),
            $list('billed_unpaid', 'B9 Largest bills not paid'),
        ];

        $integrity = $d['integrity'];
        $service = app(BillingDetailsImportService::class);
        $sections['checks'] = [
            $byGroup('B10 Tariff check by district', 'District', $a['districts'], 'name', $tariffHeads, $tariffCells),
            $byRoute('B10 Tariff check by route', array_values(array_filter($a['routes'], fn (array $r) => $r['tariff_mismatch'] > 0)), $tariffHeads, $tariffCells),
            $list('tariff_mismatch', 'B10 Tariff mismatches'),
            $byGroup('B11 Read recency by district', 'District', $a['districts'], 'name', $recencyHeads, $recencyCells),
            $byRoute('B11 Read recency by route', $a['routes'], $recencyHeads, $recencyCells),
            $list('stale_read', 'B11 Stale reads'),
            ['title' => 'B12 Integrity by upload', 'headings' => ['Upload', 'Routes checked', 'Routes that disagree with their totals', 'Accounts repeated', 'Charges do not add up', 'Negative consumption', 'Bill month outside the period'], 'rows' => array_map(fn (array $b) => [$b['period'].' (batch #'.$b['id'].')', $n($b['routes_checked']), $b['routes_failed'], $n($b['duplicates']), $integrity['components_off'], $integrity['negative_consume'], $integrity['bill_month_outside']], $integrity['batches'])],
            ['title' => 'B12 Routes flagged', 'headings' => ['Upload', 'Route', 'What disagrees'], 'rows' => array_merge(...array_map(fn (array $b) => array_map(fn (array $f) => ['batch #'.$b['id'], $f['label'], $service->describeDiffs($f['diffs'])], $b['flagged']), $integrity['batches']) ?: [[]])],
            $list('duplicate_account', 'B12 Repeated accounts'),
            $list('negative_consume', 'B12 Negative consumption'),
        ];

        $sections['trend'] = [
            ['title' => 'B13 Trend by upload', 'headings' => ['Period', 'Batch', 'Customers', 'Billed', 'Consumption', 'Month total', 'Paid', 'Collection ratio (%)', 'Estimated bills (%)', 'Unbilled (%)', 'Balance due', 'Owing', 'Billed, not paid', 'Tariff mismatch (%)', 'Read over 90 days or never (%)'], 'rows' => array_map(fn (array $p) => [$p['label'], $p['batch_id'], $p['customers'], $p['billed_customers'], $p['consume'], $p['month_total'], $p['paid'], $n($p['collection_ratio']), $n($p['estimated_share']), $n($p['unbilled_share']), $p['balance_due'], $p['owing_amount'], $p['billed_unpaid'], $n($p['tariff_mismatch_pct']), $n($p['read_over_90_share'])], $d['trend'])],
            ['title' => 'B13 Trend by district', 'headings' => ['Period', 'District', 'Customers', 'Month total', 'Paid', 'Collection ratio (%)', 'Estimated bills (%)', 'Unbilled (%)', 'Balance due'], 'rows' => array_merge(...array_map(fn (array $p) => array_map(fn (array $x) => [$p['label'], $x['name'], $x['customers'], $x['month_total'], $x['paid'], $n($x['collection_ratio']), $n($x['estimated_share']), $n($x['unbilled_share']), $x['balance_due']], $p['districts']), $d['trend']) ?: [[]])],
        ];

        $tables = $section === 'all' ? array_merge(...array_values($sections)) : $sections[$section];

        return $this->report('billing-details', $meta, $notes, $tables, $meta['Period']);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    protected function report(string $key, array $meta, array $notes, array $tables, ?string $period = null): array
    {
        return [
            'key' => $key,
            'file' => ['region' => (string) ($meta['Region'] ?? 'all'), 'period' => (string) ($period ?? $meta['Months'] ?? 'all')],
            'title' => self::REPORTS[$key]['title'],
            'meta' => $meta,
            'notes' => $notes,
            'tables' => $tables,
            'row_count' => array_sum(array_map(fn (array $table) => count($table['rows']), $tables)),
        ];
    }

    protected function inactiveRows(array $inactive, \Closure $who): array
    {
        $rows = [];

        foreach ($inactive['months'] as $month) {
            foreach ($month['zero'] as $r) {
                $rows[] = [$month['label'], ...$who($r), 0, 'No visits'];
            }

            foreach ($month['low'] as $r) {
                $rows[] = [$month['label'], ...$who($r), $r['visited'], 'Below '.$inactive['low_pct'].'% of the median ('.($r['pct_of_median'] ?? '–').'%)'];
            }
        }

        return $rows;
    }

    protected function met(?bool $met): string
    {
        return $met === null ? '' : ($met ? 'Yes' : 'No');
    }

    /** A cell for a figure that may be null. */
    protected function n(mixed $value): mixed
    {
        return $value ?? '';
    }
}
