<?php

namespace App\Services\Commercial\Cross;

/**
 * X12, extended in Phase 8h (design 8.52): the billing summary DERIVED from the Detailed Billing Report against the billing summary an officer UPLOADED for the same
 * region and period. A PURE pass over the two sets of route rows (district, route code and the columns of commercial_billing_routes) and their band tables: it
 * reads nothing and writes nothing.
 *
 * Every column is compared route by route and in total, with the tolerances of the settings (money per route, volume per route, counts exactly):
 *
 *   agrees    no route is beyond the tolerance and the totals agree;
 *   differs   at least one route (or the total) is beyond it: "differs by n (x%)" is the difference of the totals, derived minus uploaded;
 *   missing   the column has no value on one side ("not in one of them"), for example a band table the derived summary could not build.
 *
 * A route found in only one of the two sets is listed and also makes the verdict "differs". The verdict is the sentence the Commercial team reads before they switch the
 * setting to the detailed report.
 */
class DerivedSummaryCheck
{
    public const VERDICT_AGREES = 'The detailed report reproduces the billing summary for this period';

    /** key => [label, kind]. The kind picks the tolerance: volume, money or count (exact). */
    public const COLUMNS = [
        'volume_actual' => ['Volume, actual reading', 'volume'], 'volume_average' => ['Volume, average', 'volume'], 'volume_total' => ['Volume, total', 'volume'],
        'opening_balance' => ['Opening balance', 'money'], 'billing_for_period' => ['Billing for the period', 'money'], 'total_receivable' => ['Total receivable', 'money'],
        'revenue_adjustment' => ['Revenue adjustment', 'money'], 'payment_for_month' => ['Payment for the month', 'money'], 'prev_month_payment' => ['Previous-month payment', 'money'],
        'offset_payments' => ['Offset payments', 'money'], 'total_payments' => ['Total payments', 'money'], 'closing_balance' => ['Closing balance', 'money'],
        'customers_count' => ['Number of customers', 'count'],
        'billed_actual_reading' => ['Billed: actual reading', 'count'], 'billed_average_metered' => ['Billed: average, metered', 'count'], 'billed_average_unmetered' => ['Billed: average, unmetered', 'count'], 'billed_total' => ['Billed: total', 'count'],
        'unbilled_suspense_metered' => ['Unbilled: suspense, metered', 'count'], 'unbilled_suspense_unmetered' => ['Unbilled: suspense, unmetered', 'count'],
        'unbilled_disconn_metered' => ['Unbilled: disconnected, metered', 'count'], 'unbilled_disconn_unmetered' => ['Unbilled: disconnected, unmetered', 'count'],
        'unbilled_other' => ['Unbilled: other status', 'count'], 'unbilled_total' => ['Unbilled: total', 'count'],
    ];

    /** The band table's measures. */
    public const BAND_COLUMNS = ['customers' => ['Band customers', 'count'], 'volume' => ['Band volume', 'volume'], 'amount' => ['Band amount', 'money']];

    /**
     * @param  list<array<string, mixed>>  $upload  the uploaded summary's routes
     * @param  list<array<string, mixed>>  $derived  the derived summary's routes
     * @param  list<array<string, mixed>>|null  $uploadBands  band rows (band, customers, volume, amount), empty when the table is absent, null when the viewer may not see the
     *                                                         region-wide band table (a district user): the bands are then not compared at all
     * @param  list<array<string, mixed>>|null  $derivedBands
     * @param  array{money: float, volume: float}  $tolerance
     * @return array<string, mixed>
     */
    public function compare(array $upload, array $derived, ?array $uploadBands, ?array $derivedBands, array $tolerance, bool $withTotal = true, int $worstLimit = 10): array
    {
        $up = $this->index($upload);
        $dv = $this->index($derived);
        $both = array_values(array_intersect(array_keys($up), array_keys($dv)));
        $onlyUp = array_values(array_diff(array_keys($up), array_keys($dv)));
        $onlyDv = array_values(array_diff(array_keys($dv), array_keys($up)));

        $columns = [];
        $perRoute = [];   // route key => [column key => difference]

        foreach (self::COLUMNS as $key => [$label, $kind]) {
            $columns[] = $this->column($key, $label, $kind, $up, $dv, $both, $tolerance, $withTotal, $perRoute);
        }

        $bands = $uploadBands === null || $derivedBands === null ? [] : $this->bands($uploadBands, $derivedBands, $tolerance);
        $differing = array_values(array_filter($columns, fn (array $c) => $c['status'] !== 'agrees'));
        $problems = array_map(fn (array $c) => $c['label'], $differing);

        foreach ($bands as $band) {
            if ($band['status'] !== 'agrees') {
                $problems[] = $band['label'];
            }
        }

        if ($onlyUp !== [] || $onlyDv !== []) {
            $problems[] = 'routes found in only one of the two ('.(count($onlyUp) + count($onlyDv)).')';
        }

        return [
            'columns' => $columns, 'bands' => $bands, 'routes_compared' => count($both),
            'only_in_uploaded' => array_map(fn (string $k) => $this->routeName($up[$k]), $onlyUp), 'only_in_derived' => array_map(fn (string $k) => $this->routeName($dv[$k]), $onlyDv),
            'worst' => $this->worst($perRoute, $up, $worstLimit),
            'agrees' => $problems === [], 'verdict' => $problems === [] ? self::VERDICT_AGREES : 'The detailed report differs from the billing summary in: '.implode('; ', $problems),
            'tolerance' => $tolerance,
        ];
    }

    // ---------------------------------------------------------------- one column

    /**
     * @param  array<string, array<string, mixed>>  $up
     * @param  array<string, array<string, mixed>>  $dv
     * @param  list<string>  $both
     * @param  array<string, array<string, float>>  $perRoute
     * @return array<string, mixed>
     */
    protected function column(string $key, string $label, string $kind, array $up, array $dv, array $both, array $tolerance, bool $withTotal, array &$perRoute): array
    {
        $upValues = array_filter(array_column($up, $key), fn ($v) => $v !== null);
        $dvValues = array_filter(array_column($dv, $key), fn ($v) => $v !== null);

        if ($upValues === [] || $dvValues === []) {
            return ['key' => $key, 'label' => $label, 'kind' => $kind, 'status' => 'missing', 'text' => 'not in '.($upValues === [] && $dvValues === [] ? 'either of them' : ($upValues === [] ? 'the uploaded summary' : 'the derived summary')),
                'uploaded' => $upValues === [] ? null : round(array_sum($upValues), 2), 'derived' => $dvValues === [] ? null : round(array_sum($dvValues), 2), 'difference' => null, 'difference_pct' => null, 'routes_differing' => 0];
        }

        $tol = $kind === 'money' ? $tolerance['money'] : ($kind === 'volume' ? $tolerance['volume'] : 0.0);
        $differing = 0;

        foreach ($both as $route) {
            $a = $up[$route][$key] ?? null;
            $b = $dv[$route][$key] ?? null;

            if ($a === null || $b === null) {
                continue;
            }

            $diff = round((float) $b - (float) $a, 2);

            if (abs($diff) > $tol + 1e-9) {
                $differing++;
                $perRoute[$route][$key] = $diff;
            }
        }

        $upTotal = round((float) array_sum($upValues), 2);
        $dvTotal = round((float) array_sum($dvValues), 2);
        $difference = round($dvTotal - $upTotal, 2);
        // totals: counts exactly; money and volume within the per-route tolerance for every route compared (rounding cannot add up to more)
        $totalTol = $kind === 'count' ? 0.0 : $tol * max(1, count($both));
        $totalOff = $withTotal && abs($difference) > $totalTol + 1e-9;
        $off = $differing > 0 || $totalOff;
        $pct = $upTotal != 0.0 ? round($difference / abs($upTotal) * 100, 2) : null;
        $fmt = fn (float $n) => number_format($n, $kind === 'count' ? 0 : 2);

        return [
            'key' => $key, 'label' => $label, 'kind' => $kind, 'status' => $off ? 'differs' : 'agrees',
            'text' => $off ? 'differs by '.$fmt($difference).($pct !== null ? ' ('.number_format($pct, 1).'%)' : '').($differing > 0 ? ', '.$differing.' route'.($differing === 1 ? '' : 's') : '') : 'agrees',
            'uploaded' => $upTotal, 'derived' => $dvTotal, 'difference' => $difference, 'difference_pct' => $pct, 'routes_differing' => $differing,
        ];
    }

    // ---------------------------------------------------------------- bands

    /**
     * @param  list<array<string, mixed>>  $up
     * @param  list<array<string, mixed>>  $dv
     * @return list<array<string, mixed>>
     */
    protected function bands(array $up, array $dv, array $tolerance): array
    {
        $upBy = array_column($up, null, 'band');
        $dvBy = array_column($dv, null, 'band');
        $out = [];

        foreach (self::BAND_COLUMNS as $key => [$label, $kind]) {
            if ($upBy === [] || $dvBy === []) {
                $out[] = ['key' => $key, 'label' => $label.' (category 611)', 'status' => 'missing', 'text' => 'not in '.($upBy === [] && $dvBy === [] ? 'either of them' : ($upBy === [] ? 'the uploaded summary' : 'the derived summary')), 'rows' => []];

                continue;
            }

            $tol = $kind === 'money' ? $tolerance['money'] : ($kind === 'volume' ? $tolerance['volume'] : 0.0);
            $rows = [];
            $off = false;

            foreach (array_unique([...array_keys($upBy), ...array_keys($dvBy)]) as $band) {
                $a = isset($upBy[$band]) ? (float) $upBy[$band][$key] : null;
                $b = isset($dvBy[$band]) ? (float) $dvBy[$band][$key] : null;
                $diff = $a !== null && $b !== null ? round($b - $a, 2) : null;
                $bad = $diff === null || abs($diff) > $tol + 1e-9;
                $off = $off || $bad;
                $rows[] = ['band' => (string) $band, 'uploaded' => $a, 'derived' => $b, 'difference' => $diff, 'agrees' => ! $bad];
            }

            $out[] = ['key' => $key, 'label' => $label.' (category 611)', 'status' => $off ? 'differs' : 'agrees', 'text' => $off ? 'differs' : 'agrees', 'rows' => $rows];
        }

        return $out;
    }

    // ---------------------------------------------------------------- the routes that differ most

    /**
     * @param  array<string, array<string, float>>  $perRoute  route key => column key => difference (only the columns beyond the tolerance)
     * @param  array<string, array<string, mixed>>  $up
     * @return list<array<string, mixed>>
     */
    protected function worst(array $perRoute, array $up, int $limit): array
    {
        $rows = [];

        foreach ($perRoute as $route => $diffs) {
            $money = 0.0;

            foreach ($diffs as $key => $diff) {
                $money += (self::COLUMNS[$key][1] ?? '') === 'money' ? abs($diff) : 0.0;
            }

            $rows[] = ['route' => $this->routeName($up[$route]), 'district_id' => $up[$route]['district_id'] ?? null, 'columns' => count($diffs), 'money' => round($money, 2),
                'differences' => array_map(fn (string $k, float $d) => ['label' => self::COLUMNS[$k][0], 'difference' => $d], array_keys($diffs), array_values($diffs))];
        }

        usort($rows, fn (array $a, array $b) => [$b['columns'], $b['money'], $a['route']] <=> [$a['columns'], $a['money'], $b['route']]);

        return array_slice($rows, 0, max(1, $limit));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    protected function index(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[$this->key($row)] = $row;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $row */
    protected function key(array $row): string
    {
        return (($row['district_id'] ?? null) ?: 'raw:'.($row['district_label_raw'] ?? '')).'|'.($row['route_code'] ?? '');
    }

    /** @param  array<string, mixed>  $row */
    protected function routeName(array $row): string
    {
        return trim((string) ($row['district_label_raw'] ?? '')).' '.($row['route_code'] ?? '');
    }
}
