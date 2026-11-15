<?php

namespace App\Services\Commercial\BillingDetails;

use App\Models\CommercialBillingDetailBand;

/**
 * The analyses B1 to B12 of the Detailed Billing Report (design 8.39) as pure passes over ROLLUP rows that were already scoped, filtered and
 * collapsed over the chosen uploads (BillingDetailsReport does that; nothing here reads the database). Every table of every analysis is built
 * from the same "wide row" of a group (a district, a route, a service category or the whole), so the screens, the trend and the exports cannot
 * disagree.
 *
 * Units: money in whole PESEWAS and consumption in thousandths while folding (no float drift), cedis and units in the output. Ratios are
 * percentages, `null` on a zero denominator. A negative balance is a customer CREDIT: the owing and the credit parts are always separate and
 * never netted off.
 *
 * Over several uploads that do not overlap (CommercialPeriods rule 4) the collapse in the report has already applied the rules: money flows
 * were added, the previous balance is the EARLIEST period's, the balance due and every count of customers the LATEST period's; `bills`
 * (customers added over the periods) is the denominator of the per-bill figures, so an average consumption is never a sum over periods
 * divided by one period's customers.
 *
 * A row is: ['route_id', 'label', 'district_id', 'cat', 'cs' (account status), 'ms' (meter status), 'bs' (bill status), 'v' => the packed
 * columns of BillingDetailsScan (indexes C_*), with v[BILLS] = customers added over the periods].
 */
class BillingDetailsAnalytics
{
    /** The extra slot after the scan's 32 columns: customers added over the chosen periods (customer-months). */
    public const BILLS = 32;

    /** The money and consumption columns that ADD over periods (the flows). */
    public const FLOWS = [
        BillingDetailsScan::C_CONSUME, BillingDetailsScan::C_WATER, BillingDetailsScan::C_RURAL, BillingDetailsScan::C_FIRE, BillingDetailsScan::C_SEWAGE,
        BillingDetailsScan::C_SERVICE, BillingDetailsScan::C_MONTH_TOTAL, BillingDetailsScan::C_PAID, BillingDetailsScan::C_PREV_PAY, BillingDetailsScan::C_OFFSET,
        BillingDetailsScan::C_ADJUSTED, BillingDetailsScan::C_BILLED_UNPAID_AMT,
    ];

    /** The column that takes the EARLIEST period's value (the balance the range started from). */
    public const EARLIEST = [BillingDetailsScan::C_PREVIOUS];

    /** The estimation causes (B2), decided by the METER status, never by the spelling of the bill status. */
    public const CAUSES = ['F' => 'Faulty meter', 'N' => 'No meter', 'W' => 'Working meter not read', 'O' => 'Other or unknown meter status'];

    /** The bill statuses shown one by one (B1); anything else is "Other". */
    public const STATUSES = ['REGULAR', 'ESTIMATED', 'ESTIMATE', 'UNBILLED'];

    /** Exception lists: kind => the analysis it belongs to. */
    public const LIST_KINDS = [
        'active_unbilled' => 'B3', 'billed_not_active' => 'B3', 'zero_consume_regular' => 'B4', 'top_debtor' => 'B7', 'large_adjustment' => 'B8', 'billed_unpaid' => 'B9',
        'tariff_mismatch' => 'B10', 'stale_read' => 'B11', 'duplicate_account' => 'B12', 'negative_consume' => 'B12',
    ];

    /**
     * @param  list<array<string, mixed>>  $rows  collapsed rollup rows
     * @param  array{districts: array<int, string>, categories: array<string, string>, flags: array<string, array{active: bool, billing: bool}>, cca: array<int, array{staff_id: string, name: string|null}>|null, named: bool}  $ctx
     * @return array<string, mixed>
     */
    public function analyse(array $rows, array $ctx): array
    {
        $total = $this->fold($rows, fn () => 'all', $ctx);
        $districts = $this->fold($rows, fn (array $r) => $r['district_id'] !== null ? (string) $r['district_id'] : 'none', $ctx);
        $routes = $this->fold($rows, fn (array $r) => $r['route_id'] !== null ? 'r'.$r['route_id'] : 'l'.$r['label'], $ctx);
        $categories = $this->fold($rows, fn (array $r) => $r['cat'], $ctx);

        $districtRows = [];

        foreach ($districts as $key => $acc) {
            $districtRows[] = ['id' => $key === 'none' ? null : (int) $key, 'name' => $key === 'none' ? 'District not known yet' : ($ctx['districts'][(int) $key] ?? 'District '.$key)] + $this->metrics($acc, $ctx);
        }

        usort($districtRows, fn (array $a, array $b) => strcmp((string) $a['name'], (string) $b['name']));

        $routeRows = [];

        foreach ($routes as $key => $acc) {
            $first = $acc['first'];
            $cca = $first['route_id'] !== null ? ($ctx['cca'][$first['route_id']] ?? null) : null;
            $routeRows[] = [
                'route_id' => $first['route_id'], 'label' => $first['label'], 'district_id' => $first['district_id'],
                'district' => $first['district_id'] !== null ? ($ctx['districts'][$first['district_id']] ?? null) : null,
                'cca_staff_id' => $ctx['named'] ? ($cca['staff_id'] ?? null) : null, 'cca_name' => $ctx['named'] ? ($cca['name'] ?? null) : null,
            ] + $this->metrics($acc, $ctx);
        }

        usort($routeRows, fn (array $a, array $b) => [(string) $a['district'], (string) $a['label']] <=> [(string) $b['district'], (string) $b['label']]);

        $categoryRows = [];

        foreach ($categories as $key => $acc) {
            $categoryRows[] = ['code' => (string) $key, 'name' => $ctx['categories'][(string) $key] ?? ($key === '' ? '(no category)' : 'Category '.$key)] + $this->metrics($acc, $ctx);
        }

        usort($categoryRows, fn (array $a, array $b) => strcmp($a['code'], $b['code']));

        $totalMetrics = isset($total['all']) ? $this->metrics($total['all'], $ctx) : $this->metrics($this->emptyAcc(), $ctx);

        return [
            'totals' => $totalMetrics,
            'districts' => $districtRows,
            'routes' => $routeRows,
            'categories' => $categoryRows,
            'unbilled' => $this->unbilledTable($rows, $ctx),
        ];
    }

    // ---------------------------------------------------------------- folding

    /** @return array<string, array<string, mixed>> */
    protected function fold(array $rows, callable $keyOf, array $ctx): array
    {
        $out = [];

        foreach ($rows as $r) {
            $key = $keyOf($r);
            $acc = &$out[$key];
            $acc ??= array_merge($this->emptyAcc(), ['first' => $r]);
            $v = $r['v'];

            foreach ($v as $i => $value) {
                $acc['v'][$i] = ($acc['v'][$i] ?? 0) + $value;
            }

            $customers = $v[BillingDetailsScan::C_CUSTOMERS];
            $bills = $v[self::BILLS];
            $bs = $r['bs'];
            $slot = in_array($bs, self::STATUSES, true) ? $bs : 'OTHER';

            $acc['bill'][$slot][0] += $customers;
            $acc['bill'][$slot][1] += $v[BillingDetailsScan::C_MONTH_TOTAL];
            $acc['bill'][$slot][2] += $bills;

            if (in_array($bs, BillingDetailsScan::BILLED, true)) {
                $acc['billed_customers'] += $customers;
                $acc['billed_bills'] += $bills;
                $acc['billed_consume'] += $v[BillingDetailsScan::C_CONSUME];
            }

            if ($bs === 'REGULAR') {
                $acc['regular_customers'] += $customers;
                $acc['regular_zero'] += $v[BillingDetailsScan::C_ZERO];
            }

            if (in_array($bs, BillingDetailsScan::ESTIMATED, true)) {
                $cause = match ($r['ms']) {
                    'F' => 'F', 'N', '' => 'N', 'W' => 'W', default => 'O'
                };
                $acc['est'][$cause][0] += $customers;
                $acc['est'][$cause][1] += $v[BillingDetailsScan::C_MONTH_TOTAL];
                $acc['spelling'][$bs][0] += $customers;
                $acc['spelling'][$bs][1] += $v[BillingDetailsScan::C_MONTH_TOTAL];
            }

            if ($bs === 'UNBILLED') {
                $acc['unbilled'] += $customers;
                $flags = $ctx['flags'][$r['cs']] ?? null;

                if ($flags !== null && $flags['billing']) {
                    $acc['unbilled_active'] += $customers;
                }

                if ($r['cs'] === 'HOLD') {
                    $acc['unbilled_hold'] += $customers;
                }
            }

            unset($acc);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected function emptyAcc(): array
    {
        return [
            'v' => array_fill(0, 33, 0), 'bill' => array_fill_keys([...self::STATUSES, 'OTHER'], [0, 0, 0]), 'est' => array_fill_keys(array_keys(self::CAUSES), [0, 0]),
            'spelling' => ['ESTIMATED' => [0, 0], 'ESTIMATE' => [0, 0]],
            'billed_customers' => 0, 'billed_bills' => 0, 'billed_consume' => 0, 'regular_customers' => 0, 'regular_zero' => 0, 'unbilled' => 0, 'unbilled_active' => 0, 'unbilled_hold' => 0,
            'first' => ['route_id' => null, 'label' => '', 'district_id' => null],
        ];
    }

    /**
     * The wide row of one group: every figure every analysis shows, in cedis (money) and units (consumption).
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    protected function metrics(array $a, array $ctx): array
    {
        $v = $a['v'];
        $m = fn (int $p): float => round($p / 100, 2);
        $customers = $v[BillingDetailsScan::C_CUSTOMERS];
        $billed = $a['billed_customers'];
        $monthTotal = $v[BillingDetailsScan::C_MONTH_TOTAL];
        $due = $v[BillingDetailsScan::C_PREVIOUS] + $monthTotal + $v[BillingDetailsScan::C_ADJUSTED];
        $estimated = $a['bill']['ESTIMATED'][0] + $a['bill']['ESTIMATE'][0];
        $charged = [BillingDetailsScan::C_WATER => 'water', BillingDetailsScan::C_RURAL => 'rural', BillingDetailsScan::C_FIRE => 'fire', BillingDetailsScan::C_SEWAGE => 'sewage', BillingDetailsScan::C_SERVICE => 'service'];

        $bill = [];

        foreach ($a['bill'] as $status => [$count, $total]) {
            $bill[$status] = ['customers' => $count, 'month_total' => $m($total), 'share' => $this->pct($count, $customers)];
        }

        $est = [];

        foreach ($a['est'] as $cause => [$count, $total]) {
            $est[$cause] = ['customers' => $count, 'month_total' => $m($total), 'share' => $this->pct($count, $estimated)];
        }

        return [
            // customers and the bill-status mix (B1)
            'customers' => $customers, 'bills' => $v[self::BILLS], 'billed_customers' => $billed, 'bill' => $bill,
            'estimated_customers' => $estimated, 'estimated_share' => $this->pct($estimated, $billed),
            // estimation cause (B2)
            'est' => $est, 'spelling' => ['ESTIMATED' => ['customers' => $a['spelling']['ESTIMATED'][0], 'month_total' => $m($a['spelling']['ESTIMATED'][1])], 'ESTIMATE' => ['customers' => $a['spelling']['ESTIMATE'][0], 'month_total' => $m($a['spelling']['ESTIMATE'][1])]],
            'estimated_value' => $m($a['est']['F'][1] + $a['est']['N'][1] + $a['est']['W'][1] + $a['est']['O'][1]),
            // unbilled (B3)
            'unbilled' => $a['unbilled'], 'unbilled_active' => $a['unbilled_active'], 'unbilled_hold' => $a['unbilled_hold'], 'unbilled_share' => $this->pct($a['unbilled'], $customers),
            // consumption (B4)
            'consume' => round($v[BillingDetailsScan::C_CONSUME] / 1000, 3), 'consume_per_bill' => $a['billed_bills'] > 0 ? round($a['billed_consume'] / 1000 / $a['billed_bills'], 3) : null,
            'zero_regular' => $a['regular_zero'], 'zero_regular_share' => $this->pct($a['regular_zero'], $a['regular_customers']),
            // charges (B5)
            'water' => $m($v[BillingDetailsScan::C_WATER]), 'rural' => $m($v[BillingDetailsScan::C_RURAL]), 'fire' => $m($v[BillingDetailsScan::C_FIRE]), 'sewage' => $m($v[BillingDetailsScan::C_SEWAGE]), 'service' => $m($v[BillingDetailsScan::C_SERVICE]),
            'month_total' => $m($monthTotal),
            'component_share' => array_combine(array_values($charged), array_map(fn (int $i) => $this->pct($v[$i], $v[BillingDetailsScan::C_WATER] + $v[BillingDetailsScan::C_RURAL] + $v[BillingDetailsScan::C_FIRE] + $v[BillingDetailsScan::C_SEWAGE] + $v[BillingDetailsScan::C_SERVICE]), array_keys($charged))),
            // collections (B6)
            'paid' => $m($v[BillingDetailsScan::C_PAID]), 'prev_payment' => $m($v[BillingDetailsScan::C_PREV_PAY]), 'offset' => $m($v[BillingDetailsScan::C_OFFSET]),
            'collection_ratio' => $this->pct($v[BillingDetailsScan::C_PAID], $monthTotal), 'amount_due' => $m($due), 'collected_of_due' => $this->pct($v[BillingDetailsScan::C_PAID], $due),
            // receivables (B7)
            'previous_balance' => $m($v[BillingDetailsScan::C_PREVIOUS]), 'adjusted' => $m($v[BillingDetailsScan::C_ADJUSTED]), 'balance_due' => $m($v[BillingDetailsScan::C_BALANCE]),
            'owing' => $v[BillingDetailsScan::C_OWING], 'owing_amount' => $m($v[BillingDetailsScan::C_OWING_AMT]), 'credit' => $v[BillingDetailsScan::C_CREDIT], 'credit_amount' => $m($v[BillingDetailsScan::C_CREDIT_AMT]),
            // adjustments (B8)
            'adjusted_count' => $v[BillingDetailsScan::C_ADJ_COUNT],
            // billed, not paid (B9)
            'billed_unpaid' => $v[BillingDetailsScan::C_BILLED_UNPAID], 'billed_unpaid_amount' => $m($v[BillingDetailsScan::C_BILLED_UNPAID_AMT]), 'billed_unpaid_share' => $this->pct($v[BillingDetailsScan::C_BILLED_UNPAID], $billed),
            // tariff arithmetic (B10)
            'tariff_checked' => $v[BillingDetailsScan::C_TARIFF_CHECKED], 'tariff_mismatch' => $v[BillingDetailsScan::C_TARIFF_MISMATCH], 'tariff_mismatch_pct' => $this->pct($v[BillingDetailsScan::C_TARIFF_MISMATCH], $v[BillingDetailsScan::C_TARIFF_CHECKED]),
            // read recency (B11)
            'read_0_30' => $v[BillingDetailsScan::C_READ_0_30], 'read_31_60' => $v[BillingDetailsScan::C_READ_31_60], 'read_61_90' => $v[BillingDetailsScan::C_READ_61_90], 'read_over_90' => $v[BillingDetailsScan::C_READ_OVER_90], 'read_never' => $v[BillingDetailsScan::C_READ_NEVER],
            'read_over_90_share' => $this->pct($v[BillingDetailsScan::C_READ_OVER_90] + $v[BillingDetailsScan::C_READ_NEVER], $customers),
            // integrity (B12)
            'components_off' => $v[BillingDetailsScan::C_COMPONENTS], 'negative_consume' => $v[BillingDetailsScan::C_NEGATIVE], 'bill_month_outside' => $v[BillingDetailsScan::C_OUTSIDE],
        ];
    }

    /**
     * B3: the UNBILLED customers by account status and meter status (customers), with whether the status is an active billing status.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function unbilledTable(array $rows, array $ctx): array
    {
        $table = [];

        foreach ($rows as $r) {
            if ($r['bs'] !== 'UNBILLED') {
                continue;
            }

            $row = &$table[$r['cs']];
            $row ??= ['status' => $r['cs'], 'customers' => 0, 'by_meter' => []];
            $row['customers'] += $r['v'][BillingDetailsScan::C_CUSTOMERS];
            $row['by_meter'][$r['ms']] = ($row['by_meter'][$r['ms']] ?? 0) + $r['v'][BillingDetailsScan::C_CUSTOMERS];
            unset($row);
        }

        foreach ($table as $status => &$row) {
            $flags = $ctx['flags'][$status] ?? null;
            $row['active_billing'] = $flags !== null && $flags['billing'];
            $row['known'] = $flags !== null;
            ksort($row['by_meter']);
        }

        unset($row);
        ksort($table);

        return array_values($table);
    }

    /** A percentage, null on a zero denominator. */
    public function pct(int|float $part, int|float $whole): ?float
    {
        return $whole == 0 ? null : round($part / $whole * 100, 2);
    }

    /** Band rows (collapsed) as the B4 distribution: band => [customers, consume]. @param  list<array{district_id: int|null, cat: string, band: string, customers: int, consume: int}>  $bands @return list<array<string, mixed>> */
    public function bandTable(array $bands): array
    {
        $by = array_fill_keys(array_keys(CommercialBillingDetailBand::BANDS), [0, 0]);

        foreach ($bands as $b) {
            $by[$b['band']][0] += $b['customers'];
            $by[$b['band']][1] += $b['consume'];
        }

        $total = array_sum(array_column($by, 0));

        return array_map(fn (string $band) => ['band' => $band, 'customers' => $by[$band][0], 'consume' => round($by[$band][1] / 1000, 3), 'share' => $this->pct($by[$band][0], $total)], array_keys($by));
    }
}
