<?php

namespace Tests\Support\Commercial;

use App\Models\CommercialImportBatch;
use App\Services\Commercial\BillingDetails\BillingDetailsSampleFile;

/**
 * Phase 8h: the INVENTED detailed billing file and the invented billing summary uploaded for the same routes, worked out by hand. Needs a
 * CommercialTestCase that also uses BuildsBillingDetails (districts Sowutuom and Odorkor of Accra West). Nothing here comes from a real export.
 *
 * THE DETAILED FILE (bill period September-2026, three routes, 19 customers; every ordinary customer is rate 4 x consume: water = 4c, rural 2%,
 * fire 1%, sewage 35%, service 2.00):
 *
 *   SOWUTUOM 4101 (14 customers)
 *     1  REGULAR   ACTB W  611 consume 4    month total 24.08, paid 24.08
 *     2  REGULAR   ACTB W  611 consume 12   month total 68.24, paid 68.24, adjusted -3.00 (balance -3.00)
 *     3  ESTIMATED ACTB W  611 consume 10   month total 57.20, previous balance 20.00, paid 0 (balance 77.20)
 *     4  ESTIMATE  ACTB F  611 consume 5    month total 29.60, paid 29.60
 *     5  ESTIMATED ACTB N  611 consume 6    month total 35.12, paid 10.00, previous-month payment 5.00, offset 1.00 (balance 19.12)
 *     6  ESTIMATED ACTB (blank meter) 611 consume 10   month total 57.20, paid 57.20
 *     7  UNBILLED  SUSP W  previous balance 100.00        8  UNBILLED SUSP N  50.00
 *     9  UNBILLED  DISC F  30.00     10 UNBILLED DISO N 20.00     11 UNBILLED DISN W 10.00
 *     12 UNBILLED  ACTB W  0.00      13 UNBILLED NSC N  5.00      14 bill status CANCELLED (in no billed or unbilled column)
 *   ODORKOR 4201 (4 customers)
 *     15 REGULAR ACTB W 611 consume 5     29.60 paid 29.60       16 REGULAR ACTB W 611 consume 5.5   32.36 paid 32.36
 *     17 REGULAR ACTB W 612 consume 8     46.16 paid 40.00 (balance 6.16)       18 UNBILLED DISC W previous balance 7.00
 *   ODORKOR 4202 (1 customer)
 *     19 REGULAR ACTB W 611 consume 10    57.20 paid 57.20, balance printed as 12.34 (it should be 0.00: the route breaks the identity)
 *
 * THE FIGURES THE DERIVED SUMMARY MUST HAVE (consume factor 1):
 *
 *   4101: volume actual 16.00 (4+12), average 31.00 (10+5+6+10), total 47.00; opening 235.00 (20+100+50+30+20+10+5); billing 271.44; adjustment -3.00;
 *         receivable 503.44; paid this month 189.12; previous month 5.00; offset 1.00; payments 195.12; closing 308.32 (= 503.44 - 195.12);
 *         customers 14; billed actual 2, average metered 2 (3 W, 4 F), average unmetered 2 (5 N, 6 blank), total 6;
 *         unbilled suspense metered 1, suspense unmetered 1, disconnected metered 2 (9 DISC F, 11 DISN W), disconnected unmetered 1 (10 DISO N), other 2
 *         (12 ACTB, 13 NSC), total 7
 *   4201: volume 18.50 / 0 / 18.50; opening 7.00; billing 108.12; receivable 115.12; payments 101.96; closing 13.16; customers 4; billed actual 3, total 3;
 *         unbilled disconnected metered 1, total 1
 *   4202: volume 10.00 / 0 / 10.00; opening 0; billing 57.20; receivable 57.20; payments 57.20; closing 12.34 (receivable less payments is 0.00: FLAGGED);
 *         customers 1; billed actual 1
 *   category 611 bands (billed customers only): at or under 5: customers 3 (1, 4, 15), volume 14.00 (4+5+5), amount 83.28 (24.08+29.60+29.60);
 *         over 5: customers 6 (2, 3, 5, 6, 16, 19), volume 53.50 (12+10+6+10+5.5+10), amount 307.32 (68.24+57.20+35.12+57.20+32.36+57.20)
 */
trait BuildsDerivedSummary
{
    /** @return array<string, mixed> the spec of the invented detailed file */
    protected function derivedSpec(string $period = 'September-2026 - September-2026', int $offset = 0): array
    {
        $c = fn (int $n, array $over = []) => BillingDetailsSampleFile::customer($n + $offset, $over);
        $zero = ['consume' => 0, 'water' => 0, 'rural' => 0, 'fire' => 0, 'sewage' => 0, 'service' => 0, 'paid' => 0, 'bill_status' => 'UNBILLED', 'last_read' => null];

        $a = [
            $c(1, ['consume' => 4]),
            $c(2, ['consume' => 12, 'adjusted' => -3.00]),
            $c(3, ['consume' => 10, 'bill_status' => 'ESTIMATED', 'previous_balance' => 20, 'paid' => 0]),
            $c(4, ['consume' => 5, 'bill_status' => 'ESTIMATE', 'meter_status' => 'F']),
            $c(5, ['consume' => 6, 'bill_status' => 'ESTIMATED', 'meter_status' => 'N', 'paid' => 10, 'prev_payment' => 5, 'offset' => 1]),
            $c(6, ['consume' => 10, 'bill_status' => 'ESTIMATED', 'meter_status' => '']),
            $c(7, $zero + ['status' => 'SUSP', 'meter_status' => 'W', 'previous_balance' => 100]),
            $c(8, $zero + ['status' => 'SUSP', 'meter_status' => 'N', 'previous_balance' => 50]),
            $c(9, $zero + ['status' => 'DISC', 'meter_status' => 'F', 'previous_balance' => 30]),
            $c(10, $zero + ['status' => 'DISO', 'meter_status' => 'N', 'previous_balance' => 20]),
            $c(11, $zero + ['status' => 'DISN', 'meter_status' => 'W', 'previous_balance' => 10]),
            $c(12, $zero + ['status' => 'ACTB', 'meter_status' => 'W', 'previous_balance' => 0]),
            $c(13, $zero + ['status' => 'NSC', 'meter_status' => 'N', 'previous_balance' => 5]),
            $c(14, ['bill_status' => 'CANCELLED'] + $zero),
        ];

        $b = [
            $c(15, ['consume' => 5]),
            $c(16, ['consume' => 5.5]),
            $c(17, ['consume' => 8, 'category' => '612', 'paid' => 40]),
            $c(18, $zero + ['status' => 'DISC', 'meter_status' => 'W', 'previous_balance' => 7]),
        ];

        $d = [$c(19, ['consume' => 10, 'balance_due' => 12.34])];

        return ['region' => 'ACCRA WEST', 'period' => $period, 'routes' => [
            ['label' => 'SOWUTUOM 4101', 'customers' => $a],
            ['label' => 'ODORKOR 4201', 'customers' => $b],
            ['label' => 'ODORKOR 4202', 'customers' => $d],
        ]];
    }

    /** @return list<array<string, mixed>> the hand-worked derived route rows (district label as printed, code, figures) */
    protected function expectedRoutes(): array
    {
        return [
            ['district' => 'Sowutuom', 'code' => '4101',
                'volume_actual' => 16.00, 'volume_average' => 31.00, 'volume_total' => 47.00,
                'opening_balance' => 235.00, 'billing_for_period' => 271.44, 'total_receivable' => 503.44, 'revenue_adjustment' => -3.00,
                'payment_for_month' => 189.12, 'prev_month_payment' => 5.00, 'offset_payments' => 1.00, 'total_payments' => 195.12, 'closing_balance' => 308.32,
                'customers_count' => 14, 'billed_average_metered' => 2, 'billed_average_unmetered' => 2, 'billed_actual_reading' => 2, 'billed_total' => 6,
                'unbilled_suspense_metered' => 1, 'unbilled_suspense_unmetered' => 1, 'unbilled_disconn_metered' => 2, 'unbilled_disconn_unmetered' => 1, 'unbilled_other' => 2, 'unbilled_total' => 7],
            ['district' => 'Odorkor', 'code' => '4201',
                'volume_actual' => 18.50, 'volume_average' => 0.00, 'volume_total' => 18.50,
                'opening_balance' => 7.00, 'billing_for_period' => 108.12, 'total_receivable' => 115.12, 'revenue_adjustment' => 0.00,
                'payment_for_month' => 101.96, 'prev_month_payment' => 0.00, 'offset_payments' => 0.00, 'total_payments' => 101.96, 'closing_balance' => 13.16,
                'customers_count' => 4, 'billed_average_metered' => 0, 'billed_average_unmetered' => 0, 'billed_actual_reading' => 3, 'billed_total' => 3,
                'unbilled_suspense_metered' => 0, 'unbilled_suspense_unmetered' => 0, 'unbilled_disconn_metered' => 1, 'unbilled_disconn_unmetered' => 0, 'unbilled_other' => 0, 'unbilled_total' => 1],
            ['district' => 'Odorkor', 'code' => '4202',
                'volume_actual' => 10.00, 'volume_average' => 0.00, 'volume_total' => 10.00,
                'opening_balance' => 0.00, 'billing_for_period' => 57.20, 'total_receivable' => 57.20, 'revenue_adjustment' => 0.00,
                'payment_for_month' => 57.20, 'prev_month_payment' => 0.00, 'offset_payments' => 0.00, 'total_payments' => 57.20, 'closing_balance' => 12.34,
                'customers_count' => 1, 'billed_average_metered' => 0, 'billed_average_unmetered' => 0, 'billed_actual_reading' => 1, 'billed_total' => 1,
                'unbilled_suspense_metered' => 0, 'unbilled_suspense_unmetered' => 0, 'unbilled_disconn_metered' => 0, 'unbilled_disconn_unmetered' => 0, 'unbilled_other' => 0, 'unbilled_total' => 0],
        ];
    }

    /**
     * An UPLOADED billing summary for September 2026 holding the hand-worked figures above, with the changes named in $change applied to the routes
     * (code => [column => value]) and an optional band table. Written straight into the tables, as a person's upload would leave them.
     *
     * @param  array<string, array<string, mixed>>  $change
     */
    protected function uploadedTwin(array $change = [], bool $bands = true, string $month = '2026-09-01', string $segment = 'all'): CommercialImportBatch
    {
        $routes = array_map(function (array $r) use ($change) {
            return array_merge(['district' => $r['district'], 'code' => $r['code']], collect($r)->except(['district', 'code'])->all(), $change[$r['code']] ?? []);
        }, $this->expectedRoutes());

        return $this->seedBilling($routes, $month, [
            'segment' => $segment,
            'districts' => ['Sowutuom' => $this->sowutuom->id, 'Odorkor' => $this->odorkor->id],
            'bands' => $bands ? [['<=5', 3, 14.00, 83.28], ['>5', 6, 53.50, 307.32]] : false,
        ]);
    }

    protected function derivedBatch(?CommercialImportBatch $source = null): ?CommercialImportBatch
    {
        return CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_BILLING_SUMMARY)->where('source', CommercialImportBatch::SOURCE_DERIVED)
            ->when($source, fn ($q) => $q->where('derived_from_batch_id', $source->id))->orderByDesc('id')->first();
    }
}
