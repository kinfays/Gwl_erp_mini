<?php

namespace App\Services\Commercial\BillingDetails;

use DateTimeImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes a workbook in the layout of the Detailed Billing Report (rptBillingDetails_ExP) from a spec, with INVENTED data only. Used by the
 * sample command (commercial:billing-details:sample, to try the upload and the screens without a real file), by the small fixture in
 * tests/Fixtures, by the test workbook builder (Tests\Support\Commercial\ReportWorkbooks::billingDetails) and by the memory benchmark, so
 * there is one writer of the layout. It streams (OpenSpout), so a file of 70,000 customers is made in flat memory.
 *
 * The layout: a title, a filter cell ("BILL PERIOD: ..." and "REGION: ..."), then for every route a heading ("DISTRICT code"), the header row
 * (Account #, Meter #, Account Name, Service Category, Status, Meter Status, Previous Reading, Current Reading, Consume, Rate, Water Charge, Rural
 * Water (2%), Fire Fighting (1%), Sewage (35%), Service Charge, Month Total, Previous Balance, Paid This Month, Prev Month Payment, Offset
 * Payment, Adjusted, Balance Due, Bill Month, Last Read Date, Last Act Date, Last Pay Date, Bill Status), one row per customer and a totals row
 * ("<heading> TOTALS :", "Customer Count: N" in the Account Name column and the route's sums).
 *
 * $spec: region ('ACCRA WEST'), period (text as printed), title (false leaves it off), filters ('block' = one multi-line cell, 'rows' = one
 * cell per line), sheet (name), routes: list of ['label', 'customers' (a list or a generator of customer() arrays), 'totals' => false for no
 * totals row, or ['count'|'consume'|'month_total'|... => the ABSOLUTE number the totals row prints]].
 */
final class BillingDetailsSampleFile
{
    /** The district label no system knows (it asks for a match). */
    public const UNKNOWN_DISTRICT = 'ATLANTIS';

    /** The district names printed when none are given. */
    public const INVENTED_DISTRICTS = ['SAMPLE ONE', 'SAMPLE TWO', 'SAMPLE THREE'];

    /** The bill period of the sample: 21 days, so a PART of a month. */
    public const SAMPLE_PERIOD = '01-September-2026 - 21-September-2026';

    /** The money columns a totals row adds up, in the order the header prints them. */
    public const SUMS = ['consume', 'water', 'rural', 'fire', 'sewage', 'service', 'month_total', 'previous_balance', 'paid', 'prev_payment', 'offset', 'adjusted', 'balance_due'];

    public const HEADERS = [
        'Account #', 'Meter #', 'Account Name', 'Service Category', 'Status', 'Meter Status', 'Previous Reading', 'Current Reading', 'Consume', 'Rate',
        'Water Charge', 'Rural Water (2%)', 'Fire Fighting (1%)', 'Sewage (35%)', 'Service Charge', 'Month Total', 'Previous Balance', 'Paid This Month',
        'Prev Month Payment', 'Offset Payment', 'Adjusted', 'Balance Due', 'Bill Month', 'Last Read Date', 'Last Act Date', 'Last Pay Date', 'Bill Status',
    ];

    /**
     * An invented customer: every figure is consistent unless an override says otherwise (water = rate x consume, rural 2%, fire 1%, sewage
     * 35%, service 2.00, month total = their sum, paid in full, balance due = previous + month total + adjusted - paid - payments - offset).
     * The name and the meter number are invented too ("Sample Customer 7", "SMPL-000007"): they are written to the file so a test can prove the
     * importer never stores them.
     *
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    public static function customer(int $n, array $over = []): array
    {
        $consume = (float) ($over['consume'] ?? 10);
        $rate = (float) ($over['rate'] ?? 4);
        $water = round((float) ($over['water'] ?? $rate * $consume), 2);
        $rural = round((float) ($over['rural'] ?? $water * 0.02), 2);
        $fire = round((float) ($over['fire'] ?? $water * 0.01), 2);
        $sewage = round((float) ($over['sewage'] ?? $water * 0.35), 2);
        $service = round((float) ($over['service'] ?? 2.0), 2);
        $monthTotal = round((float) ($over['month_total'] ?? $water + $rural + $fire + $sewage + $service), 2);
        $previous = round((float) ($over['previous_balance'] ?? 0), 2);
        $paid = round((float) ($over['paid'] ?? $monthTotal), 2);
        $prevPayment = round((float) ($over['prev_payment'] ?? 0), 2);
        $offset = round((float) ($over['offset'] ?? 0), 2);
        $adjusted = round((float) ($over['adjusted'] ?? 0), 2);

        return [
            'account' => $over['account'] ?? str_pad((string) (100000000000 + $n), 12, '0', STR_PAD_LEFT),
            'meter_no' => 'SMPL-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'name' => 'Sample Customer '.$n,
            'category' => $over['category'] ?? '611',
            'status' => $over['status'] ?? 'ACTB',
            'meter_status' => $over['meter_status'] ?? 'W',
            'consume' => $consume, 'rate' => $rate,
            'water' => $water, 'rural' => $rural, 'fire' => $fire, 'sewage' => $sewage, 'service' => $service,
            'month_total' => $monthTotal, 'previous_balance' => $previous, 'paid' => $paid, 'prev_payment' => $prevPayment, 'offset' => $offset, 'adjusted' => $adjusted,
            'balance_due' => round((float) ($over['balance_due'] ?? $previous + $monthTotal + $adjusted - $paid - $prevPayment - $offset), 2),
            'bill_month' => $over['bill_month'] ?? 'September-2026',
            'last_read' => array_key_exists('last_read', $over) ? $over['last_read'] : '2026-09-10',   // null = the "no date" 2000-01-01
            'bill_status' => $over['bill_status'] ?? 'REGULAR',
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return string the path written ($path, or a new temporary .xlsx)
     */
    public static function write(array $spec, ?string $path = null): string
    {
        $region = $spec['region'] ?? 'ACCRA WEST';
        $period = $spec['period'] ?? 'September-2026 - September-2026';
        $path ??= tempnam(sys_get_temp_dir(), 'bill').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName($spec['sheet'] ?? 'rptBillingDetails_ExP');

        if (($spec['title'] ?? true) !== false) {
            $writer->addRow(Row::fromValues([null, 'Detailed Billing Report']));
        }

        if (($spec['filters'] ?? 'block') === 'rows') {
            $writer->addRow(Row::fromValues([null, "BILL PERIOD: {$period}"]));
            $writer->addRow(Row::fromValues([null, "REGION: {$region}"]));
        } else {
            $writer->addRow(Row::fromValues([null, "BILL PERIOD: {$period}\nREGION: {$region}\n"]));
        }

        $writer->addRow(Row::fromValues([]));

        foreach ($spec['routes'] ?? [] as $route) {
            $writer->addRow(Row::fromValues([null, $route['label']]));
            $writer->addRow(Row::fromValues([null, ...self::HEADERS]));

            $sum = array_fill_keys(self::SUMS, 0.0);
            $count = 0;

            foreach ($route['customers'] ?? [] as $c) {
                $count++;

                foreach (self::SUMS as $field) {
                    $sum[$field] += (float) $c[$field];
                }

                $writer->addRow(Row::fromValues([
                    null, $c['account'], $c['meter_no'], $c['name'], $c['category'], $c['status'], $c['meter_status'], 100, 100 + (float) $c['consume'], $c['consume'], $c['rate'],
                    $c['water'], $c['rural'], $c['fire'], $c['sewage'], $c['service'], $c['month_total'], $c['previous_balance'], $c['paid'], $c['prev_payment'], $c['offset'],
                    $c['adjusted'], $c['balance_due'], $c['bill_month'], new DateTimeImmutable($c['last_read'] ?? '2000-01-01'), new DateTimeImmutable('2000-01-01'), new DateTimeImmutable('2000-01-01'),
                    $c['bill_status'],
                ]));
            }

            if (($route['totals'] ?? []) !== false) {
                $totals = array_merge($sum, ['count' => $count], (array) ($route['totals'] ?? []));
                $writer->addRow(Row::fromValues([
                    null, $route['label'].' TOTALS :', null, 'Customer Count: '.number_format((int) $totals['count']), null, null, null, null, null, round($totals['consume'], 3), null,
                    round($totals['water'], 2), round($totals['rural'], 2), round($totals['fire'], 2), round($totals['sewage'], 2), round($totals['service'], 2), round($totals['month_total'], 2),
                    round($totals['previous_balance'], 2), round($totals['paid'], 2), round($totals['prev_payment'], 2), round($totals['offset'], 2), round($totals['adjusted'], 2),
                    round($totals['balance_due'], 2),
                ]));
            }

            $writer->addRow(Row::fromValues([]));
        }

        $writer->close();

        return $path;
    }

    /**
     * THE SAMPLE: 19 routes in three districts (30 customers each), the unassigned route 0000 (4 customers) and one route in an unknown district
     * (3 customers): 578 customer rows over 21 headings, for a part-month bill period, with every awkward case the importer and the analyses must
     * handle. Every name, id and figure is invented. The design (and the arithmetic the tests do by hand on it):
     *
     *   the bulk customer n (1..570)   category 611 (612 when n is a multiple of 3), status ACTB, working meter, consume 10 at rate 4: water 40.00,
     *                                  rural 0.80, fire 0.40, sewage 14.00, service 2.00, month total 57.20, bill REGULAR, last read 10 Sep 2026,
     *                                  paid in full (paid 0 when n is a multiple of 5: billed, not paid; balance due 57.20), bill month Sep 2026
     *   routes                         first district 4101-4108, second 4201-4206, third 4301-4305, in that order, 30 customers each (n = 1..570)
     *   n = 1                          ACTB, UNBILLED, balance due 100.00 (an ACTIVE billing account that is unbilled)
     *   n = 2                          HOLD, UNBILLED, balance due 50.00 (shown apart)
     *   n = 3                          DISC, UNBILLED
     *   n = 4                          NSC, UNBILLED (NSC is a billing status, so it is an active unbilled account)
     *   n = 5                          DISC but REGULAR and billed (a disconnected account that is billed)
     *   n = 6, 7                       ESTIMATED on WORKING meters, consume 8, last read 1 Jun 2026 (112 days: over 90)
     *   n = 8                          ESTIMATE on a FAULTY meter (F), consume 8
     *   n = 9                          ESTIMATE on NO meter (N), consume 8
     *   n = 10                         ESTIMATE with a BLANK meter status, consume 8
     *   n = 11                         a tariff mismatch: rate 5 x consume 10 = 50.00 but water 60.00 (month total 84.80)
     *   n = 12                         a zero-consumption REGULAR bill (service charge only: month total 2.00)
     *   n = 13                         last read date 2000-01-01 (never read)
     *   n = 14                         components that do not add up: month total 60.00 against 57.20 of charges
     *   n = 15                         NEGATIVE consumption (-5: charges -20.00, -0.40, -0.20, -7.00 and service 2.00, month total -25.60)
     *   n = 16                         an adjustment of -150.00 (balance due -150.00, a credit)
     *   n = 17                         previous balance 500.00 and nothing paid (balance due 557.20: the top debtor)
     *   n = 18                         previous balance -200.00 (a credit), paid 57.20 (balance due -200.00)
     *   n = 19                         Bill Month August 2026 (outside the bill period)
     *   n = 20                         paid 0, previous-month payment 20.00 and an offset of 5.00 (balance due 32.20)
     *   n = 21                         appears TWICE: its account is repeated as the last row of the last route of the third district
     *   route 4202                     its totals row says 31 customers and a month total 57.20 higher than its rows: a flagged route (one row out)
     *   route 0000                     4 customers (n = 571..574), normal
     *   ATLANTIS 9901                  3 customers (n = 575..577), normal, in a district nobody can place
     *
     * @param  list<string>  $districts  three district names as printed (routes 41xx, 42xx, 43xx)
     * @return array<string, mixed> a spec for write()
     */
    public static function sampleSpec(string $region = 'ACCRA WEST', array $districts = self::INVENTED_DISTRICTS, string $period = self::SAMPLE_PERIOD): array
    {
        $districts = array_values($districts) + self::INVENTED_DISTRICTS;
        $special = self::specials();
        // a multiple of 5 has paid nothing; everyone else paid in full (the default)
        $bulk = function (int $n) use ($special): array {
            $over = ['category' => $n % 3 === 0 ? '612' : '611'];

            if ($n % 5 === 0) {
                $over['paid'] = 0;
            }

            return self::customer($n, ($special[$n] ?? []) + $over);
        };

        $routes = [];
        $n = 0;
        $layout = [[0, 41, 8], [1, 42, 6], [2, 43, 5]];

        foreach ($layout as [$district, $hundred, $count]) {
            for ($r = 1; $r <= $count; $r++) {
                $customers = [];

                for ($i = 0; $i < 30; $i++) {
                    $customers[] = $bulk(++$n);
                }

                $routes[] = ['label' => $districts[$district].' '.($hundred * 100 + $r), 'customers' => $customers];
            }
        }

        // n = 21 appears twice: the last row of the last route of the third district repeats its account
        $last = count($routes) - 1;
        $routes[$last]['customers'][] = $bulk(21);

        // route 4202: its totals row is one row out
        foreach ($routes as $i => $route) {
            if ($route['label'] === $districts[1].' 4202') {
                $month = round(array_sum(array_column($route['customers'], 'month_total')), 2);
                $routes[$i]['totals'] = ['count' => count($route['customers']) + 1, 'month_total' => $month + 57.20];
            }
        }

        $routes[] = ['label' => $districts[0].' 0000', 'customers' => array_map($bulk, range(571, 574))];
        $routes[] = ['label' => self::UNKNOWN_DISTRICT.' 9901', 'customers' => array_map($bulk, range(575, 577))];

        return ['region' => $region, 'period' => $period, 'routes' => $routes];
    }

    /** @return array<int, array<string, mixed>> the special customers of the sample, by number (see sampleSpec()) */
    public static function specials(): array
    {
        $zero = ['consume' => 0, 'water' => 0, 'rural' => 0, 'fire' => 0, 'sewage' => 0, 'service' => 0, 'month_total' => 0, 'paid' => 0];

        return [
            1 => $zero + ['bill_status' => 'UNBILLED', 'previous_balance' => 100],
            2 => $zero + ['status' => 'HOLD', 'bill_status' => 'UNBILLED', 'previous_balance' => 50],
            3 => $zero + ['status' => 'DISC', 'bill_status' => 'UNBILLED'],
            4 => $zero + ['status' => 'NSC', 'bill_status' => 'UNBILLED'],
            5 => ['status' => 'DISC'],
            6 => ['bill_status' => 'ESTIMATED', 'consume' => 8, 'last_read' => '2026-06-01'],
            7 => ['bill_status' => 'ESTIMATED', 'consume' => 8, 'last_read' => '2026-06-01'],
            8 => ['bill_status' => 'ESTIMATE', 'meter_status' => 'F', 'consume' => 8],
            9 => ['bill_status' => 'ESTIMATE', 'meter_status' => 'N', 'consume' => 8],
            10 => ['bill_status' => 'ESTIMATE', 'meter_status' => '', 'consume' => 8],
            11 => ['rate' => 5, 'water' => 60],
            12 => ['consume' => 0, 'water' => 0, 'rural' => 0, 'fire' => 0, 'sewage' => 0],
            13 => ['last_read' => null],
            14 => ['month_total' => 60, 'paid' => 60],
            15 => ['consume' => -5, 'water' => -20, 'rural' => -0.4, 'fire' => -0.2, 'sewage' => -7, 'month_total' => -25.6, 'paid' => 0, 'balance_due' => -25.6],
            16 => ['adjusted' => -150],
            17 => ['previous_balance' => 500, 'paid' => 0],
            18 => ['previous_balance' => -200],
            19 => ['bill_month' => 'August-2026'],
            20 => ['paid' => 0, 'prev_payment' => 20, 'offset' => 5],
        ];
    }
}
