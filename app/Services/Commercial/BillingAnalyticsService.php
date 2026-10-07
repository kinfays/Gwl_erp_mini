<?php

namespace App\Services\Commercial;

use App\Models\CommercialBillingBand;
use App\Models\CommercialBillingRoute;
use App\Models\CommercialImportBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The numbers behind the billing screens (design sections 4.2 and 8.8, B1-B12), worked out in PHP from the rows it is
 * handed. The caller owns scoping: it passes already-scoped batches and the routes of the ONE snapshot being analysed.
 *
 * Rules that keep screen and numbers in step:
 *  - A billing analysis works on ONE snapshot (one effective batch). Snapshots are never summed: balances roll forward
 *    (opening / closing are not additive) and a multi-month period overlaps single months.
 *  - Every district and snapshot figure is the SUM of its route figures, and every ratio is recomputed from those sums,
 *    never an average of route ratios. Ratios are percentages to 2 dp, null when the denominator is 0.
 *  - Money ratios (GH¢ per customer, per m3) are plain 2-dp numbers, null on a zero divisor. Volumes are in thousand
 *    litres, which is 1 m3 per thousand, so GH¢ per m3 = billing / volume_total.
 *  - customers_count ("Number Of Customers") is never used: its meaning is unconfirmed (design 10 Q2).
 */
class BillingAnalyticsService
{
    /** Route columns that are summed (everything numeric except customers_count). */
    public const SUM_FIELDS = [
        'volume_actual', 'volume_average', 'volume_total',
        'opening_balance', 'billing_for_period', 'total_receivable', 'revenue_adjustment',
        'payment_for_month', 'prev_month_payment', 'offset_payments', 'total_payments', 'closing_balance',
        'billed_average_metered', 'billed_average_unmetered', 'billed_actual_reading', 'billed_total',
        'unbilled_suspense_metered', 'unbilled_suspense_unmetered', 'unbilled_disconn_metered', 'unbilled_disconn_unmetered',
        'unbilled_other', 'unbilled_total',
    ];

    public const UNBILLED_REASONS = [
        'unbilled_suspense_metered' => 'Suspense, metered',
        'unbilled_suspense_unmetered' => 'Suspense, unmetered',
        'unbilled_disconn_metered' => 'Disconnected, metered',
        'unbilled_disconn_unmetered' => 'Disconnected, unmetered',
        'unbilled_other' => 'Other status',
    ];

    public const PARETO_TOP = 10;

    public const TOP_CREDITS = 10;

    // ---------------------------------------------------------------- snapshots

    /**
     * The effective billing batches: for each (region, customer segment, period) the newest non-voided one, so a replaced
     * (superseded) batch never shows. Newest period first. Pass the batches ALREADY scoped to the actor's regions.
     *
     * @return list<array<string, mixed>>
     */
    public function snapshots(Builder $batches): array
    {
        $effective = $batches
            ->where('report_type', CommercialImportBatch::TYPE_BILLING_SUMMARY)
            ->where('status', '!=', CommercialImportBatch::STATUS_VOIDED)
            ->with('region:id,region_name')
            ->get()
            ->groupBy(fn (CommercialImportBatch $batch) => implode('|', [
                $batch->region_id, $batch->customer_segment, $batch->period_from->toDateString(), $batch->period_to->toDateString(),
            ]))
            ->map(fn (Collection $group) => $group->sortByDesc('id')->first());

        return $effective
            ->sort(fn (CommercialImportBatch $a, CommercialImportBatch $b) => [$b->period_to->toDateString(), $b->id] <=> [$a->period_to->toDateString(), $a->id])
            ->map(fn (CommercialImportBatch $batch) => $this->describe($batch))
            ->values()
            ->all();
    }

    /** The snapshot an analysis opens on: the latest single-month one, else the latest of any kind. */
    public function defaultSnapshot(array $snapshots): ?array
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot['is_single_month']) {
                return $snapshot;
            }
        }

        return $snapshots[0] ?? null;
    }

    /** @return array<string, mixed> */
    public function describe(CommercialImportBatch $batch): array
    {
        $from = $batch->period_from;
        $to = $batch->period_to;
        $single = $from->format('Y-m') === $to->format('Y-m');
        $period = $single ? $from->format('M Y') : $from->format('M').' to '.$to->format('M Y');
        $segment = $batch->customer_segment && $batch->customer_segment !== CommercialImportBatch::SEGMENT_ALL
            ? str($batch->customer_segment)->replace('_', ' ')->title()->toString()
            : 'All customers';

        return [
            'id' => $batch->id,
            'batch' => $batch,
            'region_id' => $batch->region_id,
            'region' => $batch->region?->region_name ?? $batch->region_label_raw ?? 'Unknown region',
            'segment' => $batch->customer_segment,
            'segment_label' => $segment,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'month' => $from->format('Y-m-01'),
            'period_label' => $period,
            'is_single_month' => $single,
            'label' => ($batch->region?->region_name ?? $batch->region_label_raw ?? 'Unknown region').' · '.$segment.' · '.$period.($single ? '' : ' (period)'),
        ];
    }

    // ---------------------------------------------------------------- B1 + B8 overview

    /**
     * B1 + B8: the snapshot's totals, districts and routes ranked, and how much of the billing the top routes carry.
     *
     * @param  Collection<int, CommercialBillingRoute>  $routes
     * @param  string  $rankBy  billing | volume | billed
     * @return array<string, mixed>
     */
    public function overview(Collection $routes, string $rankBy = 'billing'): array
    {
        $rankField = ['billing' => 'billing_for_period', 'volume' => 'volume_total', 'billed' => 'billed_total'][$rankBy] ?? 'billing_for_period';
        $totals = $this->totals($routes);
        $billing = $totals['billing_for_period'];

        $districts = [];

        foreach ($routes->groupBy('district_label_raw') as $label => $group) {
            $sum = $this->totals($group);
            $districts[] = [
                'district' => $this->districtName($group->first(), (string) $label),
                'key' => (string) $label,
                'routes' => $group->count(),
                'billing' => $sum['billing_for_period'],
                'volume' => $sum['volume_total'],
                'billed' => (int) $sum['billed_total'],
                'share' => ReadingAnalyticsService::rate($sum['billing_for_period'], $billing),
                'per_customer' => self::ratio($sum['billing_for_period'], $sum['billed_total']),
                'per_m3' => self::ratio($sum['billing_for_period'], $sum['volume_total']),
                'rank_value' => $sum[$rankField],
            ];
        }

        usort($districts, fn (array $a, array $b) => [$b['rank_value'], $a['district']] <=> [$a['rank_value'], $b['district']]);

        $ranked = $routes->sortBy([
            fn ($a, $b) => (float) $b->{$rankField} <=> (float) $a->{$rankField},
            fn ($a, $b) => strcmp((string) $a->route_code, (string) $b->route_code),
        ])->values();

        $cumulative = 0.0;
        $routeRows = [];

        foreach ($ranked as $index => $route) {
            $cumulative += (float) $route->billing_for_period;
            $routeRows[] = [
                'rank' => $index + 1,
                'district' => $this->districtName($route, (string) $route->district_label_raw),
                'key' => (string) $route->district_label_raw,
                'route' => $route->route_code,
                'billing' => (float) $route->billing_for_period,
                'volume' => (float) $route->volume_total,
                'billed' => (int) $route->billed_total,
                'share' => ReadingAnalyticsService::rate((float) $route->billing_for_period, $billing),
                'per_customer' => self::ratio((float) $route->billing_for_period, (float) $route->billed_total),
                'per_m3' => self::ratio((float) $route->billing_for_period, (float) $route->volume_total),
            ];
        }

        // Pareto is always about billing, whatever the table is ranked by.
        $byBilling = $routes->sortByDesc(fn ($route) => (float) $route->billing_for_period)->values();
        $topBilling = $byBilling->take(self::PARETO_TOP)->sum(fn ($route) => (float) $route->billing_for_period);

        return [
            'totals' => $totals + $this->metrics($totals) + ['routes' => $routes->count()],
            'rank_by' => $rankBy,
            'districts' => $districts,
            'routes' => $routeRows,
            'pareto' => [
                'top_n' => self::PARETO_TOP,
                'routes' => min(self::PARETO_TOP, $routes->count()),
                'total_routes' => $routes->count(),
                'billing' => round($topBilling, 2),
                'share' => $billing > 0 ? ReadingAnalyticsService::rate($topBilling, $billing) : null,
            ],
        ];
    }

    // ---------------------------------------------------------------- B2 roll-forward

    /**
     * B2: opening + billing + adjustment = receivable; minus payments = closing, per district.
     *
     * @return array{districts: list<array<string, mixed>>, total: array<string, mixed>}
     */
    public function rollForward(Collection $routes): array
    {
        $row = function (string $name, string $key, Collection $group): array {
            $t = $this->totals($group);

            return [
                'district' => $name,
                'key' => $key,
                'opening' => $t['opening_balance'],
                'billing' => $t['billing_for_period'],
                'adjustment' => $t['revenue_adjustment'],
                'receivable' => $t['total_receivable'],
                'payments' => $t['total_payments'],
                'closing' => $t['closing_balance'],
                // The report's own receivable and closing must follow from the lines above them.
                'balances' => abs($t['total_receivable'] - ($t['opening_balance'] + $t['billing_for_period'] + $t['revenue_adjustment'])) <= 0.05
                    && abs($t['closing_balance'] - ($t['total_receivable'] - $t['total_payments'])) <= 0.05,
            ];
        };

        $districts = [];

        foreach ($routes->groupBy('district_label_raw') as $label => $group) {
            $districts[] = $row($this->districtName($group->first(), (string) $label), (string) $label, $group);
        }

        usort($districts, fn (array $a, array $b) => strcmp($a['district'], $b['district']));

        return ['districts' => $districts, 'total' => $row('All districts', '', $routes)];
    }

    // ---------------------------------------------------------------- B3 + B4 collections

    /**
     * B3 + B4: cash collection ratio, how much was collected against this period's billing, and how much of the cash was
     * arrears from earlier months.
     *
     * @return array{districts: list<array<string, mixed>>, total: array<string, mixed>, target: float}
     */
    public function collections(Collection $routes): array
    {
        $row = function (string $name, string $key, Collection $group): array {
            $t = $this->totals($group);

            return [
                'district' => $name,
                'key' => $key,
                'billing' => $t['billing_for_period'],
                'payments' => $t['total_payments'],
                'payment_for_month' => $t['payment_for_month'],
                'prev_month_payment' => $t['prev_month_payment'],
                'offset_payments' => $t['offset_payments'],
                'cash_ratio' => ReadingAnalyticsService::rate($t['total_payments'], $t['billing_for_period']),
                'current_ratio' => ReadingAnalyticsService::rate($t['payment_for_month'], $t['billing_for_period']),
                'prior_share' => ReadingAnalyticsService::rate($t['prev_month_payment'], $t['total_payments']),
            ];
        };

        $districts = [];

        foreach ($routes->groupBy('district_label_raw') as $label => $group) {
            $districts[] = $row($this->districtName($group->first(), (string) $label), (string) $label, $group);
        }

        usort($districts, fn (array $a, array $b) => [$b['billing'], $a['district']] <=> [$a['billing'], $b['district']]);

        return [
            'districts' => $districts,
            'total' => $row('All districts', '', $routes),
            'target' => (float) config('gwl.commercial_target_collection_pct', 95),
        ];
    }

    // ---------------------------------------------------------------- B7 balances

    /**
     * B7: routes whose closing balance is negative (treated as customer credits, to be confirmed with the Commercial
     * team): how many, how much, and the largest.
     *
     * @return array<string, mixed>
     */
    public function balances(Collection $routes): array
    {
        $credits = $routes->filter(fn ($route) => (float) $route->closing_balance < 0);

        $row = fn (string $name, string $key, Collection $group): array => [
            'district' => $name,
            'key' => $key,
            'routes' => $group->count(),
            'credit_routes' => $group->filter(fn ($route) => (float) $route->closing_balance < 0)->count(),
            'credit_amount' => round(abs($group->filter(fn ($route) => (float) $route->closing_balance < 0)->sum(fn ($route) => (float) $route->closing_balance)), 2),
            'closing' => round($group->sum(fn ($route) => (float) $route->closing_balance), 2),
        ];

        $districts = [];

        foreach ($routes->groupBy('district_label_raw') as $label => $group) {
            $districts[] = $row($this->districtName($group->first(), (string) $label), (string) $label, $group);
        }

        usort($districts, fn (array $a, array $b) => [$b['credit_amount'], $a['district']] <=> [$a['credit_amount'], $b['district']]);

        $largest = $credits->sortBy(fn ($route) => (float) $route->closing_balance)->take(self::TOP_CREDITS)->values()
            ->map(fn ($route) => [
                'district' => $this->districtName($route, (string) $route->district_label_raw),
                'key' => (string) $route->district_label_raw,
                'route' => $route->route_code,
                'credit' => round(abs((float) $route->closing_balance), 2),
            ])->all();

        return [
            'total' => $row('All districts', '', $routes),
            'credit_share' => ReadingAnalyticsService::rate($credits->count(), $routes->count()),
            'districts' => $districts,
            'largest' => $largest,
        ];
    }

    // ---------------------------------------------------------------- B5 + B6 + B10

    /**
     * B5 + B6 + B10: how much billing rests on estimates, how many customers go unbilled and why, and the billed mix.
     *
     * @return array<string, mixed>
     */
    public function estimationAndUnbilled(Collection $routes): array
    {
        $row = function (string $name, string $key, Collection $group): array {
            $t = $this->totals($group);
            $estimated = $t['billed_average_metered'] + $t['billed_average_unmetered'];

            $reasons = [];

            foreach (self::UNBILLED_REASONS as $field => $label) {
                $reasons[$field] = ['label' => $label, 'count' => (int) $t[$field], 'share' => ReadingAnalyticsService::rate($t[$field], $t['unbilled_total'])];
            }

            return [
                'district' => $name,
                'key' => $key,
                'billed' => (int) $t['billed_total'],
                'unbilled' => (int) $t['unbilled_total'],
                'unbilled_rate' => ReadingAnalyticsService::rate($t['unbilled_total'], $t['billed_total'] + $t['unbilled_total']),
                'reasons' => $reasons,
                // B5: by volume (average / total) and by count (estimated bills / all bills).
                'estimation_volume' => ReadingAnalyticsService::rate($t['volume_average'], $t['volume_total']),
                'estimation_count' => ReadingAnalyticsService::rate($estimated, $t['billed_total']),
                // B10: the three ways a bill is produced.
                'avg_metered' => (int) $t['billed_average_metered'],
                'avg_unmetered' => (int) $t['billed_average_unmetered'],
                'actual' => (int) $t['billed_actual_reading'],
                'mix' => [
                    'avg_metered' => ReadingAnalyticsService::rate($t['billed_average_metered'], $t['billed_total']),
                    'avg_unmetered' => ReadingAnalyticsService::rate($t['billed_average_unmetered'], $t['billed_total']),
                    'actual' => ReadingAnalyticsService::rate($t['billed_actual_reading'], $t['billed_total']),
                ],
                // The report splits volume only into actual-reading and average.
                'volume_actual' => $t['volume_actual'],
                'volume_average' => $t['volume_average'],
                'volume_total' => $t['volume_total'],
            ];
        };

        $districts = [];

        foreach ($routes->groupBy('district_label_raw') as $label => $group) {
            $districts[] = $row($this->districtName($group->first(), (string) $label), (string) $label, $group);
        }

        usort($districts, fn (array $a, array $b) => strcmp($a['district'], $b['district']));

        return ['total' => $row('All districts', '', $routes), 'districts' => $districts];
    }

    // ---------------------------------------------------------------- B9 bands

    /**
     * B9: the domestic (category 611) consumption bands, from the snapshot's own band table.
     *
     * @param  Collection<int, CommercialBillingBand>  $bands
     * @return array<string, mixed>
     */
    public function bands(Collection $bands): array
    {
        if ($bands->isEmpty()) {
            return ['has_bands' => false, 'category' => null, 'rows' => [], 'total' => null];
        }

        $customers = (int) $bands->sum('customers');
        $volume = (float) $bands->sum(fn ($band) => (float) $band->volume);
        $amount = (float) $bands->sum(fn ($band) => (float) $band->amount);

        $rows = $bands->sortBy('id')->map(fn ($band) => [
            'band' => $band->band,
            'customers' => (int) $band->customers,
            'volume' => (float) $band->volume,
            'amount' => (float) $band->amount,
            'customer_share' => ReadingAnalyticsService::rate($band->customers, $customers),
            'volume_share' => ReadingAnalyticsService::rate((float) $band->volume, $volume),
            'amount_share' => ReadingAnalyticsService::rate((float) $band->amount, $amount),
            'per_m3' => self::ratio((float) $band->amount, (float) $band->volume),
            'per_customer' => self::ratio((float) $band->amount, (float) $band->customers),
        ])->values()->all();

        return [
            'has_bands' => true,
            'category' => $bands->first()->category_code,
            'rows' => $rows,
            'total' => [
                'customers' => $customers,
                'volume' => round($volume, 2),
                'amount' => round($amount, 2),
                'per_m3' => self::ratio($amount, $volume),
                'per_customer' => self::ratio($amount, $customers),
            ],
        ];
    }

    // ---------------------------------------------------------------- B11 exceptions

    /**
     * B11: routes worth a look. A percentage is only judged on a route with at least commercial_exception_min_customers
     * customers behind it, so a one-customer route can never be flagged on a percentage.
     *
     * "Customers behind it" is the base of the percentage: billed + unbilled for the unbilled rate, billed for the
     * estimation share.
     *
     * @param  array{min_customers?: int, high_unbilled_pct?: float, high_estimation_pct?: float, credit_amount?: float}|null  $thresholds
     *                                                                                                                                  what-if values (the Settings preview); any key left out is the configured one
     * @return array<string, mixed>
     */
    public function exceptions(Collection $routes, ?array $thresholds = null): array
    {
        $minCustomers = (int) ($thresholds['min_customers'] ?? config('gwl.commercial_exception_min_customers', 3));
        $highUnbilled = (float) ($thresholds['high_unbilled_pct'] ?? config('gwl.commercial_exception_high_unbilled_pct', 25));
        $highEstimation = (float) ($thresholds['high_estimation_pct'] ?? config('gwl.commercial_exception_high_estimation_pct', 75));
        $creditAmount = (float) ($thresholds['credit_amount'] ?? config('gwl.commercial_exception_credit_amount', 300));

        $rows = [];
        $counts = ['zero_activity' => 0, 'heavy_credit' => 0, 'high_unbilled' => 0, 'high_estimation' => 0];

        foreach ($routes as $route) {
            $billed = (int) $route->billed_total;
            $unbilled = (int) $route->unbilled_total;
            $estimated = (int) $route->billed_average_metered + (int) $route->billed_average_unmetered;
            $unbilledRate = ReadingAnalyticsService::rate($unbilled, $billed + $unbilled);
            $estimationRate = ReadingAnalyticsService::rate($estimated, $billed);
            $closing = (float) $route->closing_balance;
            $flags = [];

            if ((float) $route->volume_total == 0.0 && $billed === 0) {
                $flags[] = 'zero_activity';
            }

            if ($closing <= -$creditAmount) {
                $flags[] = 'heavy_credit';
            }

            if ($billed + $unbilled >= $minCustomers && $unbilledRate !== null && $unbilledRate > $highUnbilled) {
                $flags[] = 'high_unbilled';
            }

            if ($billed >= $minCustomers && $estimationRate !== null && $estimationRate > $highEstimation) {
                $flags[] = 'high_estimation';
            }

            if ($flags === []) {
                continue;
            }

            foreach ($flags as $flag) {
                $counts[$flag]++;
            }

            $rows[] = [
                'district' => $this->districtName($route, (string) $route->district_label_raw),
                'key' => (string) $route->district_label_raw,
                'route' => $route->route_code,
                'flags' => $flags,
                'billed' => $billed,
                'unbilled' => $unbilled,
                'unbilled_rate' => $unbilledRate,
                'estimation_rate' => $estimationRate,
                'closing' => $closing,
                'volume' => (float) $route->volume_total,
            ];
        }

        usort($rows, fn (array $a, array $b) => [count($b['flags']), $a['route']] <=> [count($a['flags']), $b['route']]);

        return [
            'thresholds' => [
                'min_customers' => $minCustomers,
                'high_unbilled_pct' => $highUnbilled,
                'high_estimation_pct' => $highEstimation,
                'credit_amount' => $creditAmount,
            ],
            'counts' => $counts,
            'rows' => $rows,
            'routes' => $routes->count(),
        ];
    }

    // ---------------------------------------------------------------- B12 compare + trend

    /**
     * B12: two SINGLE-MONTH snapshots of the same region and segment, district by district. The earlier one is the
     * baseline whichever order they are passed in.
     *
     * @param  array{meta: array<string, mixed>, routes: Collection}  $one
     * @param  array{meta: array<string, mixed>, routes: Collection}  $two
     * @return array<string, mixed>
     */
    public function compare(array $one, array $two): array
    {
        $reason = $this->comparable($one['meta'], $two['meta']);

        if ($reason !== null) {
            return ['ok' => false, 'reason' => $reason];
        }

        [$base, $latest] = $one['meta']['period_from'] <= $two['meta']['period_from'] ? [$one, $two] : [$two, $one];

        $names = $base['routes']->pluck('district_label_raw')->merge($latest['routes']->pluck('district_label_raw'))->unique()->sort()->values();
        $rows = [];

        foreach ($names as $name) {
            $rows[] = $this->compareRow(
                $this->districtName($base['routes']->firstWhere('district_label_raw', $name) ?? $latest['routes']->firstWhere('district_label_raw', $name), (string) $name),
                (string) $name,
                $base['routes']->where('district_label_raw', $name),
                $latest['routes']->where('district_label_raw', $name),
            );
        }

        return [
            'ok' => true,
            'base' => $base['meta'],
            'latest' => $latest['meta'],
            'districts' => $rows,
            'total' => $this->compareRow('All districts', '', $base['routes'], $latest['routes']),
        ];
    }

    /**
     * B12 trend: the month-by-month series of every single-month snapshot of one region and segment. Needs at least three.
     *
     * @param  list<array{meta: array<string, mixed>, routes: Collection}>  $snapshots
     * @return array<string, mixed>
     */
    public function trend(array $snapshots): array
    {
        $single = array_values(array_filter($snapshots, fn (array $snapshot) => $snapshot['meta']['is_single_month']));
        $needed = 3;

        if (count($single) < $needed) {
            return [
                'ok' => false,
                'reason' => 'A monthly trend needs at least '.$needed.' single-month snapshots of the same region and segment; there '.(count($single) === 1 ? 'is' : 'are').' '.count($single).'. Export billing one month at a time to build it.',
            ];
        }

        usort($single, fn (array $a, array $b) => $a['meta']['month'] <=> $b['meta']['month']);

        $months = array_map(function (array $snapshot): array {
            $t = $this->totals($snapshot['routes']);
            $m = $this->metrics($t);

            return [
                'batch_id' => $snapshot['meta']['id'],
                'month' => $snapshot['meta']['month'],
                'label' => Carbon::parse($snapshot['meta']['month'])->format('M Y'),
                'billing' => $t['billing_for_period'],
                'payments' => $t['total_payments'],
                'cash_ratio' => $m['cash_ratio'],
                'current_ratio' => $m['current_ratio'],
                'estimation_volume' => $m['estimation_volume'],
                'unbilled_rate' => $m['unbilled_rate'],
            ];
        }, $single);

        return ['ok' => true, 'months' => $months];
    }

    /** Why two snapshots cannot be compared, or null when they can. */
    public function comparable(array $a, array $b): ?string
    {
        if ($a['id'] === $b['id']) {
            return 'Pick two different snapshots to compare.';
        }

        if (! $a['is_single_month'] || ! $b['is_single_month']) {
            return 'A multi-month period overlaps single months, so it cannot be compared or trended. Compare two single-month snapshots.';
        }

        if ($a['region_id'] !== $b['region_id'] || $a['segment'] !== $b['segment']) {
            return 'Only snapshots of the same region and customer segment can be compared.';
        }

        return null;
    }

    // ---------------------------------------------------------------- building blocks

    /**
     * Sums of every route figure (customers_count excluded).
     *
     * @return array<string, float>
     */
    public function totals(Collection $routes): array
    {
        $totals = array_fill_keys(self::SUM_FIELDS, 0.0);

        foreach ($routes as $route) {
            foreach (self::SUM_FIELDS as $field) {
                $totals[$field] += (float) ($route->{$field} ?? 0);
            }
        }

        return array_map(fn (float $value) => round($value, 2), $totals);
    }

    /**
     * The ratios of a set of sums (never of route ratios).
     *
     * @param  array<string, float>  $t
     * @return array<string, float|null>
     */
    public function metrics(array $t): array
    {
        return [
            'cash_ratio' => ReadingAnalyticsService::rate($t['total_payments'], $t['billing_for_period']),
            'current_ratio' => ReadingAnalyticsService::rate($t['payment_for_month'], $t['billing_for_period']),
            'prior_share' => ReadingAnalyticsService::rate($t['prev_month_payment'], $t['total_payments']),
            'estimation_volume' => ReadingAnalyticsService::rate($t['volume_average'], $t['volume_total']),
            'estimation_count' => ReadingAnalyticsService::rate($t['billed_average_metered'] + $t['billed_average_unmetered'], $t['billed_total']),
            'unbilled_rate' => ReadingAnalyticsService::rate($t['unbilled_total'], $t['billed_total'] + $t['unbilled_total']),
            'per_customer' => self::ratio($t['billing_for_period'], $t['billed_total']),
            'per_m3' => self::ratio($t['billing_for_period'], $t['volume_total']),
        ];
    }

    /** A plain 2-dp ratio (GH¢ per customer or per m3), null on a zero divisor. */
    public static function ratio(float|int $numerator, float|int $denominator): ?float
    {
        return $denominator != 0 ? round($numerator / $denominator, 2) : null;
    }

    protected function districtName(?CommercialBillingRoute $route, string $label): string
    {
        return (string) ($route?->district?->district_name ?: $label);
    }

    /**
     * @return array<string, mixed>
     */
    protected function compareRow(string $name, string $key, Collection $before, Collection $after): array
    {
        $a = $this->totals($before);
        $b = $this->totals($after);
        $ma = $this->metrics($a);
        $mb = $this->metrics($b);

        $points = fn (?float $now, ?float $then) => $now !== null && $then !== null ? round($now - $then, 2) : null;

        return [
            'district' => $name,
            'key' => $key,
            'billing_before' => $a['billing_for_period'],
            'billing_after' => $b['billing_for_period'],
            'billing_change' => round($b['billing_for_period'] - $a['billing_for_period'], 2),
            'billing_change_pct' => $a['billing_for_period'] != 0 ? round(($b['billing_for_period'] - $a['billing_for_period']) / abs($a['billing_for_period']) * 100, 2) : null,
            'payments_before' => $a['total_payments'],
            'payments_after' => $b['total_payments'],
            'payments_change' => round($b['total_payments'] - $a['total_payments'], 2),
            'cash_ratio_before' => $ma['cash_ratio'],
            'cash_ratio_after' => $mb['cash_ratio'],
            'cash_ratio_change' => $points($mb['cash_ratio'], $ma['cash_ratio']),
            'estimation_before' => $ma['estimation_volume'],
            'estimation_after' => $mb['estimation_volume'],
            'estimation_change' => $points($mb['estimation_volume'], $ma['estimation_volume']),
            'unbilled_before' => $ma['unbilled_rate'],
            'unbilled_after' => $mb['unbilled_rate'],
            'unbilled_change' => $points($mb['unbilled_rate'], $ma['unbilled_rate']),
        ];
    }
}
