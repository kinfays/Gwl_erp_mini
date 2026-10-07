<?php

namespace App\Services\Commercial;

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
        'readers' => ['title' => 'Meter reading: readers', 'formats' => ['excel', 'pdf'], 'permissions' => ['commercial.view_reader_performance'], 'any' => []],
        'billing' => ['title' => 'Billing snapshot', 'formats' => ['excel', 'pdf'], 'permissions' => ['commercial.view_billing'], 'any' => []],
        'scorecard' => ['title' => 'District scorecard', 'formats' => ['excel', 'pdf'], 'permissions' => ['commercial.view_billing', 'commercial.view_reading'], 'any' => []],
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
            $q['unmatched_readers'] !== null ? ['Readers not in the staff directory', $q['unmatched_readers']] : null,
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
            $notes[] = 'This home district has fewer than '.$data['min_readers'].' readers with visits in these months, so its figures are not shown without reader-level access (they would be an individual reader\'s).';
        }

        return $this->report('reading-trend', $meta, $notes, [
            ['title' => 'Month by month', 'headings' => ['Month', 'In progress', 'Visited', 'Read', 'Skipped', 'Read rate (%)', 'Skip rate (%)', 'Readers active', 'Verified strength', 'Coverage (%)'], 'rows' => array_map(fn (array $r) => [
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
        $whoHeads = ['Staff ID', 'Reader', 'Home district', 'Directory'];

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
            ['title' => 'Inactive and under-used readers', 'headings' => ['Month', ...$whoHeads, 'Visited', 'Why'], 'rows' => $this->inactiveRows($d['inactive'], $who)],
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
            'The district is the reader\'s home district in the staff directory; the report has no district split.',
            'Movement, inactive, workload, consistency, outliers and the scorecard use complete months only; the current month is in progress.',
            'The scorecard is indicative (weights: volume '.($w['volume'] * 100).'%, skip rate '.($w['skip'] * 100).'%, consistency '.($w['consistency'] * 100).'%); it starts conversations, it is not a verdict.',
        ], $tables);
    }

    // ---------------------------------------------------------------- billing

    /** @param  array<string, mixed>  $d  CommercialReportData::billingSnapshot() */
    public function billing(array $d): array
    {
        $o = $d['overview'];
        $notes = ['One snapshot only: snapshots are never added together. Ratios are worked out from the sums, not averaged over routes.'];

        if (! $d['snapshot']['is_single_month']) {
            $notes[] = 'This is a multi-month period: it overlaps single months and cannot be compared or trended.';
        }

        $notes[] = 'Negative balances are treated as customer credits (to be confirmed).';

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
            ['title' => 'Collections', 'headings' => ['District', 'Billing (GH¢)', 'Payments (GH¢)', 'This period', 'Earlier months', 'Offsets', 'Cash collection ratio (%)', 'Against this period (%)', 'Arrears share of cash (%)'], 'rows' => array_map(fn (array $r) => [
                $r['key'] === '' ? 'All shown' : $r['district'], $r['billing'], $r['payments'], $r['payment_for_month'], $r['prev_month_payment'], $r['offset_payments'], $this->n($r['cash_ratio']), $this->n($r['current_ratio']), $this->n($r['prior_share']),
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

    // ---------------------------------------------------------------- scorecard (C1)

    /** @param  array<string, mixed>  $d  CommercialReportData::scorecard() */
    public function scorecard(array $d): array
    {
        $c = $d['scorecard'];
        $snapshot = $d['snapshot'];
        $notes = [
            'Reading is grouped by the reader\'s HOME district in the staff directory: an approximation, as the reading report has no district split.',
            'Reading figures are the complete months inside the billing period'.($c['reading_months'] === [] ? ': there are none yet.' : ': '.implode(', ', $c['reading_months']).'.'),
            'Coverage is not shown per district: the verified strength is region-wide.',
        ];

        if ($c['segment_note']) {
            array_unshift($notes, CommercialInsightsService::notLikeForLikeNote($snapshot));
        }

        if ($c['hide_small_groups']) {
            $notes[] = 'A district\'s reading figures are left out where fewer than '.$c['min_readers'].' readers had visits: they would be one person\'s own figures.';
        }

        return $this->report('scorecard', ['Region' => $snapshot['region'], 'Billing snapshot' => $snapshot['label']], $notes, [
            ['title' => 'District scorecard', 'headings' => ['District', 'Billing (GH¢)', 'Payments (GH¢)', 'Cash collection ratio (%)', 'Estimated volume (%)', 'Unbilled rate (%)', 'Visits', 'Reads', 'Skip rate (%)', 'Active readers', 'Visits per reader', 'Reading figures'], 'rows' => array_map(fn (array $r) => [
                $r['district'], $this->n($r['billing']), $this->n($r['payments']), $this->n($r['cash_ratio']), $this->n($r['estimation']), $this->n($r['unbilled_rate']),
                $this->n($r['visits']), $this->n($r['read']), $this->n($r['skip_rate']), $this->n($r['active_readers']), $this->n($r['visits_per_reader']),
                $r['reading_hidden'] ? 'Fewer than '.$c['min_readers'].' readers' : '',
            ], $c['rows'])],
        ], $snapshot['period_label']);
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
